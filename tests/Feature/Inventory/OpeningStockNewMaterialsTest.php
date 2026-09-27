<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Services\OpeningStockSheetService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\Item;
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
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The factory's counting sheet names materials the masters do not have
 * yet — SLES, CAPB, "B- ARBOUTIN", "Tio". Each unknown code, given with a
 * name and a unit, is added exactly as the sheet names it when the stock
 * is posted; rows with the same code are batches of one material.
 */
class OpeningStockNewMaterialsTest extends TestCase
{
    use RefreshDatabase;

    private Facility $rudrapur;

    private Warehouse $rm;

    private Warehouse $pm;

    private Warehouse $fg;

    private User $admin;

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
        $this->fg = $this->rudrapur->stores()->where('type', WarehouseType::FinishedGoods->value)->firstOrFail();

        $this->kg = Uom::where('code', 'KG')->sole();

        $this->admin = User::factory()->create(['name' => 'Prashant']);
        $this->admin->assignRole(RoleName::SuperAdmin->value);
    }

    #[Test]
    public function unknown_codes_come_back_as_new_materials_named_as_the_sheet_names_them(): void
    {
        RawMaterial::factory()->create(['code' => 'CAPB', 'name' => 'Cocamidopropyl Betaine', 'stock_uom_id' => $this->kg->id]);
        PackagingMaterial::factory()->create(['code' => 'PM-1001', 'name' => 'Bottle 100 ml']);
        $before = Item::query()->count();

        $result = $this->actingAs($this->admin)->post(route('stores.opening-stock.parse', $this->rm), [
            'sheet' => $this->sheet([
                ['SLES', 'Sodium Lauryl Ether Sulphate', 'B-01', '120', 'KG', '', '', '95', ''],
                ['CAPB', 'Cocamidopropyl Betaine', 'B-02', '40', 'KG', '', '', '', ''],
                ['SDH', 'Sodium Dehydroacetate', 'B-03', '5', 'Kg', '', '', '', ''],
                ['SDH', 'Sodium Dehydroacetate', 'B-04', '2.5', 'Kgs', '', '', '', ''],
                ['B- ARBOUTIN', 'Beta Arbutin', 'B-05', '1', 'kg', '', '', '', ''],
                ['Tio', 'Titanium Dioxide', 'B-06', '3', 'KG', '', '', '', ''],
                ['XTM', 'Xanthan Gum', 'B-07', '2', '', '', '', '', ''],
                ['', 'Glycerine', 'B-08', '10', 'KG', '', '', '', ''],
                ['PM-1001', 'Bottle 100 ml', 'B-09', '10', 'PCS', '', '', '', ''],
                ['VT', 'Vitamin E', 'B-10', '1', 'KG', '', '', '', ''],
                ['VT', 'Vanilla Tincture', 'B-11', '1', 'KG', '', '', '', ''],
            ]),
        ])->assertOk()->json();

        $this->assertSame($before, Item::query()->count(), 'Reading the sheet adds nothing; posting does.');

        $codes = array_column($result['new_materials'], 'code');
        $this->assertSame(['SLES', 'SDH', 'B- ARBOUTIN', 'Tio', 'VT'], $codes, 'Codes kept exactly as typed.');
        $this->assertSame([4, 5], collect($result['new_materials'])->firstWhere('code', 'SDH')['rows'], 'Two rows, one material.');
        $this->assertSame('Beta Arbutin', collect($result['new_materials'])->firstWhere('code', 'B- ARBOUTIN')['name']);
        $this->assertSame('KG', collect($result['new_materials'])->firstWhere('code', 'SDH')['unit'], 'Kg and Kgs are read as KG.');

        $lines = collect($result['lines'])->keyBy('row');
        $this->assertCount(7, $lines);
        $this->assertNull($lines[2]['item_id']);
        $this->assertSame(['code' => 'SLES', 'name' => 'Sodium Lauryl Ether Sulphate'], $lines[2]['new_item']);
        $this->assertSame($this->kg->id, $lines[2]['uom_id']);
        $this->assertNotNull($lines[3]['item_id'], 'A code already on file is matched, not added again.');
        $this->assertNull($lines[3]['new_item']);

        $problems = implode("\n", $result['problems']);
        $this->assertStringContainsString('Row 8: "Xanthan Gum" (XTM) is new: fill in its unit', $problems);
        $this->assertStringContainsString('Row 9: no material on file matches "Glycerine"', $problems);
        $this->assertStringContainsString('Row 10: code "PM-1001" is already Bottle 100 ml, a packaging material', $problems);
        $this->assertStringContainsString('Row 12: code "VT" is "Vitamin E" on row 11 but "Vanilla Tincture" here', $problems);
    }

    #[Test]
    public function posting_adds_the_new_materials_and_books_their_batches(): void
    {
        $this->actingAs($this->admin)->post(route('facilities.opening-stock.store', $this->rudrapur), [
            'warehouse_id' => (string) $this->rm->id,
            'as_of' => now()->toDateString(),
            'remarks' => 'Counting sheet',
            'lines' => [
                $this->newLine('SLES', 'Sodium Lauryl Ether Sulphate', 'B-01', '120', '95'),
                $this->newLine('SDH', 'Sodium Dehydroacetate', 'B-03', '5'),
                $this->newLine('SDH', 'Sodium Dehydroacetate', 'B-04', '2.5'),
                $this->newLine('B- ARBOUTIN', 'Beta Arbutin', 'B-05', '1'),
                $this->newLine('Tio', 'Titanium Dioxide', 'B-06', '3'),
            ],
        ])->assertSessionHasNoErrors()->assertRedirect(route('stores.show', $this->rm));

        $this->assertStringContainsString('4 new materials added', Inertia::getFlashed()['toast']['message'] ?? '');

        $added = Item::query()->where('type', ItemType::RawMaterial->value)->orderBy('id')->get();
        $this->assertSame(['SLES', 'SDH', 'B- ARBOUTIN', 'Tio'], $added->pluck('code')->all());
        $this->assertSame('Titanium Dioxide', $added->firstWhere('code', 'Tio')->name);
        $this->assertTrue($added->every(fn (Item $i) => $i->stock_uom_id === $this->kg->id && $i->is_active && $i->created_by === $this->admin->id));

        $sdh = RawMaterial::query()->where('code', 'SDH')->sole();
        $this->assertSame(['B-03', 'B-04'], InventoryLot::query()->where('item_id', $sdh->id)->orderBy('batch_number')->pluck('batch_number')->all());
        $this->assertTrue(app(StockBalanceService::class)->onHand($sdh, $this->rm)->isEqualTo('7.5'));
        $this->assertEquals(95, (float) InventoryLot::query()->where('batch_number', 'B-01')->sole()->unit_cost);

        // The same sheet uploaded again now finds them on file.
        $again = $this->actingAs($this->admin)->post(route('stores.opening-stock.parse', $this->rm), [
            'sheet' => $this->sheet([['Tio', 'Titanium Dioxide', 'B-20', '1', 'KG', '', '', '', '']]),
        ])->json();
        $this->assertSame([], $again['new_materials']);
        $this->assertSame($added->firstWhere('code', 'Tio')->id, $again['lines'][0]['item_id']);
    }

    #[Test]
    public function a_packaging_store_adds_packaging_materials_and_a_finished_goods_store_adds_nothing(): void
    {
        $pcs = Uom::where('code', 'PCS')->sole();

        $this->actingAs($this->admin)->post(route('facilities.opening-stock.store', $this->rudrapur), [
            'warehouse_id' => (string) $this->pm->id,
            'lines' => [[...$this->newLine('CAP-28', 'Flip cap 28 mm', 'P-1', '500'), 'uom_id' => (string) $pcs->id]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(ItemType::PackagingMaterial, Item::query()->where('code', 'CAP-28')->sole()->type);

        $parsed = $this->actingAs($this->admin)->post(route('stores.opening-stock.parse', $this->fg), [
            'sheet' => $this->sheet([['FG-NEW', 'New Face Wash', 'F-1', '10', 'PCS', '', '', '', '']]),
        ])->json();
        $this->assertSame([], $parsed['lines']);
        $this->assertStringContainsString('no product on file matches code "FG-NEW"', $parsed['problems'][0]);

        $this->actingAs($this->admin)->post(route('facilities.opening-stock.store', $this->rudrapur), [
            'warehouse_id' => (string) $this->fg->id,
            'lines' => [[...$this->newLine('FG-NEW', 'New Face Wash', 'F-1', '10'), 'uom_id' => (string) $pcs->id]],
        ])->assertStatus(422);

        $this->assertFalse(Item::query()->where('code', 'FG-NEW')->exists());
    }

    #[Test]
    public function only_someone_who_may_add_materials_can_add_them_from_a_sheet(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole(RoleName::WarehouseManager->value);

        $parsed = $this->actingAs($manager)->post(route('stores.opening-stock.parse', $this->rm), [
            'sheet' => $this->sheet([['SLES', 'Sodium Lauryl Ether Sulphate', 'B-01', '120', 'KG', '', '', '', '']]),
        ])->json();
        $this->assertSame([], $parsed['new_materials']);
        $this->assertStringContainsString('your role cannot add materials', $parsed['problems'][0]);

        $this->actingAs($manager)->post(route('facilities.opening-stock.store', $this->rudrapur), [
            'warehouse_id' => (string) $this->rm->id,
            'lines' => [$this->newLine('SLES', 'Sodium Lauryl Ether Sulphate', 'B-01', '120')],
        ])->assertForbidden();

        $this->assertFalse(Item::query()->where('code', 'SLES')->exists());
    }

    #[Test]
    public function a_posting_that_fails_leaves_no_material_behind(): void
    {
        $capb = RawMaterial::factory()->create(['code' => 'CAPB', 'stock_uom_id' => $this->kg->id]);
        InventoryLot::factory()->create(['item_id' => $capb->id, 'batch_number' => 'DUP-1']);

        $this->actingAs($this->admin)->post(route('facilities.opening-stock.store', $this->rudrapur), [
            'warehouse_id' => (string) $this->rm->id,
            'lines' => [
                $this->newLine('SLES', 'Sodium Lauryl Ether Sulphate', 'B-01', '120'),
                ['item_id' => (string) $capb->id, 'quantity' => '5', 'batch_number' => 'DUP-1', 'uom_id' => ''],
            ],
        ])->assertSessionHasErrors('lines');

        $this->assertFalse(Item::query()->where('code', 'SLES')->exists(), 'All or nothing.');

        // A new line without its unit is refused before anything is written.
        $this->actingAs($this->admin)->post(route('facilities.opening-stock.store', $this->rudrapur), [
            'warehouse_id' => (string) $this->rm->id,
            'lines' => [[...$this->newLine('SLES', 'Sodium Lauryl Ether Sulphate', 'B-01', '120'), 'uom_id' => '']],
        ])->assertSessionHasErrors('lines.0.uom_id');
    }

    /**
     * @return array<string, mixed>
     */
    private function newLine(string $code, string $name, string $batch, string $quantity, string $rate = ''): array
    {
        return [
            'item_id' => '',
            'new_item' => ['code' => $code, 'name' => $name],
            'quantity' => $quantity,
            'uom_id' => (string) $this->kg->id,
            'batch_number' => $batch,
            'manufactured_at' => '',
            'expiry_at' => '',
            'unit_cost' => $rate,
            'remarks' => '',
        ];
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
}
