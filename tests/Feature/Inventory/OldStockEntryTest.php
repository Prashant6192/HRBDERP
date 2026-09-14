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
use App\Domain\MasterData\Models\Product;
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
use Inertia\Testing\AssertableInertia;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Issue #7: the stock already on the shelf when the ERP goes live, booked
 * by the administrator from one screen into the right stores, QC passed.
 */
class OldStockEntryTest extends TestCase
{
    use RefreshDatabase;

    private Facility $rudrapur;

    private Warehouse $rm;

    private Warehouse $pm;

    private Warehouse $fg;

    private RawMaterial $betaine;

    private PackagingMaterial $bottle;

    private Product $faceWash;

    private User $admin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->rudrapur = Facility::factory()->manufacturing()->withStores([WarehouseType::RawMaterial, WarehouseType::Packaging, WarehouseType::FinishedGoods, WarehouseType::Quarantine])->create(['code' => 'RDP', 'name' => 'Rudrapur Factory', 'opening_stock_enabled' => true]);
        $this->rm = $this->rudrapur->stores()->where('type', WarehouseType::RawMaterial->value)->firstOrFail();
        $this->pm = $this->rudrapur->stores()->where('type', WarehouseType::Packaging->value)->firstOrFail();
        $this->fg = $this->rudrapur->stores()->where('type', WarehouseType::FinishedGoods->value)->firstOrFail();

        $kg = Uom::where('code', 'KG')->sole();
        $pcs = Uom::where('code', 'PCS')->sole();
        $this->betaine = RawMaterial::factory()->create(['code' => 'RM-1001', 'name' => 'Cocamidopropyl Betaine', 'stock_uom_id' => $kg->id, 'requires_qc' => true, 'shelf_life_days' => 730]);
        $this->bottle = PackagingMaterial::factory()->create(['code' => 'PM-1001', 'name' => 'Bottle 100 ml', 'stock_uom_id' => $pcs->id]);
        $this->faceWash = Product::factory()->create(['code' => 'FG-1001', 'name' => 'Face Wash 100 ml', 'stock_uom_id' => $pcs->id, 'requires_qc' => true]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(RoleName::SuperAdmin->value);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(RoleName::Owner->value);
    }

    #[Test]
    public function only_the_system_administrator_sees_the_screen(): void
    {
        $this->actingAs($this->owner)->get(route('opening-stock.index'))->assertForbidden();
        $this->actingAs($this->owner)->post(route('opening-stock.store'), [])->assertForbidden();

        $this->actingAs($this->admin)->get(route('opening-stock.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('opening-stock/index')
                ->where('facility.code', 'RDP')
                ->has('kinds', 3)
                ->where('kinds.0.key', 'raw_material')
                ->where('kinds.0.stores.0.value', $this->rm->id)
                ->has('kinds.0.items', 1)
                ->where('kinds.1.stores.0.value', $this->pm->id)
                ->where('kinds.2.stores.0.value', $this->fg->id)
                ->where('kinds.2.items.0.value', $this->faceWash->id));
    }

    #[Test]
    public function raw_packaging_and_finished_stock_are_booked_in_one_go_into_their_own_stores_with_qc_passed(): void
    {
        $balances = app(StockBalanceService::class);

        $this->actingAs($this->admin)->post(route('opening-stock.store'), [
            'facility_id' => $this->rudrapur->id,
            'as_of' => '2026-09-01',
            'remarks' => 'Physical count before go-live',
            'sections' => [
                ['kind' => 'raw_material', 'warehouse_id' => $this->rm->id, 'lines' => [
                    ['item_id' => $this->betaine->id, 'quantity' => '120.5', 'batch_number' => 'OLD-CAPB-1', 'manufactured_at' => '2026-06-01', 'unit_cost' => '150'],
                ]],
                ['kind' => 'packaging', 'warehouse_id' => $this->pm->id, 'lines' => [
                    ['item_id' => $this->bottle->id, 'quantity' => '5000', 'unit_cost' => '4.20'],
                ]],
                ['kind' => 'finished_goods', 'warehouse_id' => $this->fg->id, 'lines' => [
                    ['item_id' => $this->faceWash->id, 'quantity' => '860', 'batch_number' => 'FW-OLD-01', 'manufactured_at' => '2026-07-15', 'expiry_at' => '2028-07-14', 'unit_cost' => '48'],
                ]],
            ],
        ])->assertRedirect(route('opening-stock.index', ['facility' => $this->rudrapur->id]))->assertSessionHasNoErrors();

        $this->assertSame(3, InventoryTransaction::query()->where('type', InventoryTransactionType::OpeningBalance->value)->count(), 'One posting per store');
        $this->assertTrue($balances->onHand($this->betaine, $this->rm)->isEqualTo('120.5'));
        $this->assertTrue($balances->onHand($this->bottle, $this->pm)->isEqualTo('5000'));
        $this->assertTrue($balances->onHand($this->faceWash, $this->fg)->isEqualTo('860'));
        $this->assertTrue($balances->available($this->betaine, $this->rm)->isEqualTo('120.5'), 'Usable at once: nothing waits in quarantine');

        $lot = InventoryLot::query()->where('batch_number', 'OLD-CAPB-1')->sole();
        $this->assertSame(LotQcStatus::Approved, $lot->qc_status, 'QC passed automatically');
        $this->assertSame($this->admin->id, $lot->qc_decided_by);
        $this->assertNotNull($lot->qc_decided_at);
        $this->assertTrue($lot->qc_status->isReleasable());
        $this->assertSame('2028-05-31', $lot->expiry_at?->toDateString(), 'Expiry from the shelf life (730 days) when not given');
        $this->assertSame('2026-09-01', $lot->received_at?->toDateString());

        // The screen shows what was booked.
        $this->actingAs($this->admin)->get(route('opening-stock.index', ['facility' => $this->rudrapur->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('booked', 3));
    }

    #[Test]
    public function a_line_into_the_wrong_kind_of_store_or_at_a_closed_facility_is_refused_whole(): void
    {
        $this->actingAs($this->admin)->post(route('opening-stock.store'), [
            'facility_id' => $this->rudrapur->id,
            'sections' => [
                ['kind' => 'raw_material', 'warehouse_id' => $this->fg->id, 'lines' => [['item_id' => $this->betaine->id, 'quantity' => '1']]],
            ],
        ])->assertSessionHasErrors('sections.0.warehouse_id');

        $this->rudrapur->forceFill(['opening_stock_enabled' => false])->save();

        $this->actingAs($this->admin)->post(route('opening-stock.store'), [
            'facility_id' => $this->rudrapur->id,
            'sections' => [
                ['kind' => 'raw_material', 'warehouse_id' => $this->rm->id, 'lines' => [['item_id' => $this->betaine->id, 'quantity' => '1']]],
                ['kind' => 'packaging', 'warehouse_id' => $this->pm->id, 'lines' => [['item_id' => $this->bottle->id, 'quantity' => '1']]],
            ],
        ])->assertSessionHasErrors('sections');

        $this->assertSame(0, InventoryTransaction::count(), 'All or nothing');
    }

    #[Test]
    public function the_sheet_template_downloads_and_a_filled_sheet_is_matched_row_by_row(): void
    {
        $response = $this->actingAs($this->admin)->get(route('opening-stock.template', 'raw_material'))->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('PK', $response->getContent(), 'A real .xlsx');

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray(OpeningStockSheetService::COLUMNS, null, 'A1');
        $sheet->fromArray([
            ['RM-1001', '', 'OLD-CAPB-2', '75', 'KG', '01/06/2026', '', '150', 'Drum A'],
            ['', 'cocamidopropyl betaine', 'OLD-CAPB-3', 25, 'kg', '', '2028-01-31', 148.5, ''],
            ['RM-9999', 'Unknown Material', '', '10', 'KG', '', '', '', ''],
            ['', 'Bottle 100 ml', '', '10', 'PCS', '', '', '', ''],
            ['RM-1001', '', 'OLD-CAPB-4', 'abc', 'KG', '', '', '', ''],
        ], null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'sheet').'.xlsx';
        (new Xlsx($book))->save($path);

        $result = $this->actingAs($this->admin)->post(route('opening-stock.parse'), [
            'kind' => 'raw_material',
            'sheet' => new UploadedFile($path, 'count.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ])->assertOk()->json();

        $this->assertCount(2, $result['lines']);
        $this->assertSame($this->betaine->id, $result['lines'][0]['item_id']);
        $this->assertSame('75', $result['lines'][0]['quantity']);
        $this->assertSame('2026-06-01', $result['lines'][0]['manufactured_at'], 'Indian day-first date');
        $this->assertSame('OLD-CAPB-2', $result['lines'][0]['batch_number']);
        $this->assertSame($this->betaine->id, $result['lines'][1]['item_id'], 'Matched by name when the code is blank');
        $this->assertSame('2028-01-31', $result['lines'][1]['expiry_at']);
        $this->assertSame('148.5', $result['lines'][1]['unit_cost']);

        $this->assertCount(3, $result['problems']);
        $this->assertStringContainsString('RM-9999', $result['problems'][0]);
        $this->assertStringContainsString('Bottle 100 ml', $result['problems'][1], 'A packaging material is not a raw material');
        $this->assertStringContainsString('abc', $result['problems'][2]);

        // The template's own example rows are never booked by mistake.
        $template = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($template, $response->getContent());
        $parsed = app(OpeningStockSheetService::class)->parse($template, 'raw_material');
        $this->assertSame([], $parsed['lines']);
        $this->assertSame([], $parsed['problems']);
        $this->assertSame('Materials on file', IOFactory::load($template)->getSheet(1)->getTitle());
    }
}
