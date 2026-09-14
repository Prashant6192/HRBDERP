<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\InventoryTransaction;
use App\Domain\Inventory\Services\OpeningStockSheetService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\MasterData\Models\PackagingMaterial;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Issues #7 and #11: the old stock a factory already holds, booked from the
 * facility's own opening-stock screen — typed in or read off a counting
 * sheet — with QC passed automatically.
 */
class OpeningStockSheetTest extends TestCase
{
    use RefreshDatabase;

    private Facility $rudrapur;

    private Warehouse $rm;

    private Warehouse $pm;

    private RawMaterial $betaine;

    private PackagingMaterial $bottle;

    private User $manager;

    private Uom $kg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->rudrapur = Facility::factory()->manufacturing()
            ->withStores([WarehouseType::RawMaterial, WarehouseType::Packaging, WarehouseType::FinishedGoods, WarehouseType::Quarantine])
            ->create(['code' => 'RDP', 'name' => 'Rudrapur Factory', 'opening_stock_enabled' => true]);

        $this->rm = $this->rudrapur->stores()->where('type', WarehouseType::RawMaterial->value)->firstOrFail();
        $this->pm = $this->rudrapur->stores()->where('type', WarehouseType::Packaging->value)->firstOrFail();

        $this->kg = Uom::where('code', 'KG')->sole();
        $pcs = Uom::where('code', 'PCS')->sole();

        $this->betaine = RawMaterial::factory()->create(['code' => 'RM-1001', 'name' => 'Cocamidopropyl Betaine', 'stock_uom_id' => $this->kg->id, 'requires_qc' => true, 'shelf_life_days' => 730]);
        $this->bottle = PackagingMaterial::factory()->create(['code' => 'PM-1001', 'name' => 'Bottle 100 ml', 'stock_uom_id' => $pcs->id]);

        $this->manager = User::factory()->create(['name' => 'Mahesh']);
        $this->manager->assignRole(RoleName::WarehouseManager->value);
    }

    private function sheet(array $rows): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray(OpeningStockSheetService::COLUMNS, null, 'A1');
        $sheet->fromArray($rows, null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'count').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'count.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    #[Test]
    public function the_template_comes_from_the_store_and_lists_only_that_stores_materials(): void
    {
        $raw = $this->actingAs($this->manager)->get(route('stores.opening-stock.template', $this->rm))->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $raw->headers->get('Content-Type'));
        $this->assertStringContainsString($this->rm->code, (string) $raw->headers->get('Content-Disposition'));

        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $raw->getContent());
        $book = IOFactory::load($path);

        $this->assertSame('Materials on file', $book->getSheet(1)->getTitle());
        $listed = collect($book->getSheet(1)->toArray())->skip(1)->pluck(1)->filter()->all();
        $this->assertContains('Cocamidopropyl Betaine', $listed);
        $this->assertNotContains('Bottle 100 ml', $listed, 'A raw material store sheet does not offer packaging.');

        // The packaging store's sheet is the other way round.
        $pmPath = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($pmPath, $this->actingAs($this->manager)->get(route('stores.opening-stock.template', $this->pm))->getContent());
        $pmListed = collect(IOFactory::load($pmPath)->getSheet(1)->toArray())->skip(1)->pluck(1)->filter()->all();
        $this->assertContains('Bottle 100 ml', $pmListed);
        $this->assertNotContains('Cocamidopropyl Betaine', $pmListed);
    }

    #[Test]
    public function a_filled_sheet_is_matched_row_by_row_and_the_rest_reported(): void
    {
        $result = $this->actingAs($this->manager)->post(route('stores.opening-stock.parse', $this->rm), [
            'sheet' => $this->sheet([
                ['RM-1001', '', 'OLD-CAPB-1', '75', 'KG', '01/06/2026', '', '150', 'Drum A'],
                ['', 'cocamidopropyl betaine', 'OLD-CAPB-2', 25, 'kg', '', '2028-01-31', 148.5, ''],
                ['RM-9999', 'Unknown Material', '', '10', 'KG', '', '', '', ''],
                ['', 'Bottle 100 ml', '', '10', 'PCS', '', '', '', ''],
                ['RM-1001', '', 'OLD-CAPB-3', 'abc', 'KG', '', '', '', ''],
            ]),
        ])->assertOk()->json();

        $this->assertCount(2, $result['lines']);
        $this->assertSame($this->betaine->id, $result['lines'][0]['item_id']);
        $this->assertSame('75', $result['lines'][0]['quantity']);
        $this->assertSame('2026-06-01', $result['lines'][0]['manufactured_at'], 'Indian dates are day first.');
        $this->assertSame($this->betaine->id, $result['lines'][1]['item_id'], 'Matched by name when the code is blank.');
        $this->assertSame('148.5', $result['lines'][1]['unit_cost']);

        $this->assertCount(3, $result['problems']);
        $this->assertStringContainsString('RM-9999', $result['problems'][0]);
        $this->assertStringContainsString('Bottle 100 ml', $result['problems'][1], 'Packaging is not a raw material.');
        $this->assertStringContainsString('abc', $result['problems'][2]);
    }

    #[Test]
    public function opening_stock_is_booked_into_the_store_with_qc_passed(): void
    {
        $this->actingAs($this->manager)->post(route('facilities.opening-stock.store', $this->rudrapur), [
            'warehouse_id' => $this->rm->id,
            'as_of' => '2026-09-01',
            'remarks' => 'Physical count before go-live',
            'lines' => [
                ['item_id' => $this->betaine->id, 'quantity' => '120.5', 'batch_number' => 'OLD-CAPB-1', 'manufactured_at' => '2026-06-01', 'unit_cost' => '150'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $balances = app(StockBalanceService::class);
        $this->assertTrue($balances->onHand($this->betaine, $this->rm)->isEqualTo('120.5'));
        $this->assertTrue($balances->available($this->betaine, $this->rm)->isEqualTo('120.5'), 'Usable at once: nothing waits in quarantine.');

        $lot = InventoryLot::query()->where('batch_number', 'OLD-CAPB-1')->sole();
        $this->assertSame(LotQcStatus::Approved, $lot->qc_status);
        $this->assertSame($this->manager->id, $lot->qc_decided_by);
        $this->assertNotNull($lot->qc_decided_at);
        $this->assertSame('2028-05-31', $lot->expiry_at?->toDateString(), 'Expiry from the 730-day shelf life.');

        $this->assertSame(1, InventoryTransaction::query()->where('type', InventoryTransactionType::OpeningBalance->value)->count());
    }

    #[Test]
    public function the_sheet_belongs_to_people_who_may_book_opening_stock(): void
    {
        $packer = User::factory()->create();
        $packer->assignRole(RoleName::PackagingExecutive->value);

        $this->actingAs($packer)->get(route('stores.opening-stock.template', $this->rm))->assertForbidden();
        $this->actingAs($packer)->post(route('stores.opening-stock.parse', $this->rm), ['sheet' => $this->sheet([])])->assertForbidden();
    }

    #[Test]
    public function the_old_standalone_screen_is_gone(): void
    {
        $this->assertFalse(app('router')->has('opening-stock.index'), 'Old stock entry now lives on the facility screen.');
        $this->assertTrue(app('router')->has('stores.opening-stock.template'));
    }
}
