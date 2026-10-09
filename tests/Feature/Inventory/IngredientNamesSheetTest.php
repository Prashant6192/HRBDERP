<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Services\InventoryLedgerService;
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
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Stock → Rudrapur's raw material store → the ingredient list in Excel.
 * Codes and names are corrected in the sheet, uploaded, looked over, and
 * applied together; each row finds its ingredient by ERP id.
 */
class IngredientNamesSheetTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $rm;

    private Warehouse $pm;

    private User $admin;

    private RawMaterial $acb;

    private RawMaterial $amla;

    private RawMaterial $argan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $rudrapur = Facility::factory()->manufacturing()
            ->withStores([WarehouseType::RawMaterial, WarehouseType::Packaging])
            ->create(['code' => 'RDP', 'name' => 'Rudrapur Factory']);
        $this->rm = $rudrapur->stores()->where('type', WarehouseType::RawMaterial->value)->firstOrFail();
        $this->pm = $rudrapur->stores()->where('type', WarehouseType::Packaging->value)->firstOrFail();

        $kg = Uom::where('code', 'KG')->sole();
        $this->acb = RawMaterial::factory()->create(['code' => 'ACB', 'name' => 'Acne Buster', 'stock_uom_id' => $kg->id]);
        $this->amla = RawMaterial::factory()->create(['code' => 'AMLA', 'name' => 'Amla Extract', 'stock_uom_id' => $kg->id]);
        $this->argan = RawMaterial::factory()->create(['code' => 'AGO', 'name' => 'Argon Oil', 'stock_uom_id' => $kg->id]);
        PackagingMaterial::factory()->create(['code' => 'PM-1', 'name' => 'Bottle 100 ml']);

        $lot = InventoryLot::factory()->forItem($this->amla)->create(['qc_status' => LotQcStatus::Approved]);
        app(InventoryLedgerService::class)->receive($this->amla, $this->rm, '13.66', $lot, InventoryTransactionType::StockAdjustmentIn);

        $this->admin = User::factory()->create(['name' => 'Prashant']);
        $this->admin->assignRole(RoleName::SuperAdmin->value);
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function sheet(array $rows): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['ERP ID (do not change)', 'Code', 'Name', 'Unit', 'On hand in this store'], ...$rows], null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'ing').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'ingredients.xlsx', null, null, true);
    }

    #[Test]
    public function the_list_downloads_with_every_ingredient_and_what_the_store_holds(): void
    {
        $this->actingAs($this->admin)->get(route('stock.index', ['warehouse' => $this->rm->id]))
            ->assertInertia(fn (Assert $page) => $page->where('can.ingredient_sheet', true)->where('can.ingredient_upload', true));
        $this->actingAs($this->admin)->get(route('stock.index', ['warehouse' => $this->pm->id]))
            ->assertInertia(fn (Assert $page) => $page->where('can.ingredient_sheet', false));

        $response = $this->actingAs($this->admin)->get(route('stores.ingredients.download', $this->rm))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'dl').'.xlsx';
        file_put_contents($path, $response->getContent());
        $rows = IOFactory::load($path)->getSheet(0)->toArray(null, true, false, false);

        $this->assertSame(['ERP ID (do not change)', 'Code', 'Name', 'Unit', 'On hand in this store'], $rows[0]);
        // Ingredients only, by name; packaging is not on it.
        $this->assertSame(['ACB', 'AMLA', 'AGO'], array_column(array_slice($rows, 1), 1));
        $this->assertSame([$this->acb->id, $this->amla->id, $this->argan->id], array_map('intval', array_column(array_slice($rows, 1), 0)));
        $this->assertSame(['0', '13.66', '0'], array_map('strval', array_column(array_slice($rows, 1), 4)));
        $this->assertSame('KG', $rows[1][3]);

        // Only a raw material store has the list.
        $this->actingAs($this->admin)->get(route('stores.ingredients.download', $this->pm))->assertNotFound();
    }

    #[Test]
    public function an_edited_sheet_shows_every_change_and_what_cannot_be_used(): void
    {
        $result = $this->actingAs($this->admin)->post(route('stores.ingredients.read', $this->rm), [
            'sheet' => $this->sheet([
                [$this->acb->id, 'ACB', 'Acne Buster Complex'],      // name changed
                [$this->amla->id, 'AMLA-01', 'Amla Extract'],        // code changed
                [$this->argan->id, 'ACB', 'Argan Oil'],              // takes ACB's code: clashes
                [999999, 'X', 'Nobody'],                             // no such ingredient
            ]),
        ])->assertOk()->json();

        $byId = collect($result['changes'])->keyBy('id');
        $this->assertSame(3, $byId->count());
        $this->assertNull($byId[$this->acb->id]['problem']);
        $this->assertSame('Acne Buster Complex', $byId[$this->acb->id]['name']);
        $this->assertNull($byId[$this->amla->id]['problem']);
        $this->assertSame('AMLA', $byId[$this->amla->id]['old_code']);
        $this->assertStringContainsString('already used by Acne Buster', $byId[$this->argan->id]['problem']);
        $this->assertStringContainsString('"999999" is not an ingredient', $result['problems'][0]);

        // Nothing changed yet.
        $this->assertSame('Acne Buster', $this->acb->refresh()->name);
    }

    #[Test]
    public function the_changes_are_applied_together_and_recorded(): void
    {
        $this->actingAs($this->admin)->from(route('stock.index'))->post(route('stores.ingredients.apply', $this->rm), [
            'changes' => [
                ['id' => $this->acb->id, 'code' => 'ACB-01', 'name' => 'Acne  Buster Complex '],
                ['id' => $this->argan->id, 'code' => 'AGO', 'name' => 'Argan Oil'],
            ],
        ])->assertRedirect(route('stock.index'));

        $this->assertSame('2 ingredients updated.', Inertia::getFlashed()['toast']['message'] ?? null);
        $this->assertSame(['ACB-01', 'Acne Buster Complex'], [$this->acb->refresh()->code, $this->acb->name]);
        $this->assertSame('Argan Oil', $this->argan->refresh()->name);
        $this->assertSame($this->admin->id, $this->acb->updated_by);

        $audit = AuditLog::query()->where('auditable_type', $this->acb->getMorphClass())->where('auditable_id', $this->acb->id)->latest('id')->firstOrFail();
        $this->assertSame('ACB', $audit->old_values['code']);
        $this->assertSame('ACB-01', $audit->new_values['code']);
    }

    #[Test]
    public function two_ingredients_can_swap_codes(): void
    {
        $this->actingAs($this->admin)->post(route('stores.ingredients.apply', $this->rm), [
            'changes' => [
                ['id' => $this->acb->id, 'code' => 'AMLA', 'name' => 'Acne Buster'],
                ['id' => $this->amla->id, 'code' => 'ACB', 'name' => 'Amla Extract'],
            ],
        ])->assertRedirect();

        $this->assertSame('AMLA', $this->acb->refresh()->code);
        $this->assertSame('ACB', $this->amla->refresh()->code);
    }

    #[Test]
    public function one_wrong_change_means_none_is_made(): void
    {
        $deleted = RawMaterial::factory()->create(['code' => 'OLD-1', 'name' => 'Old Oil']);
        $deleted->delete();

        $this->actingAs($this->admin)->post(route('stores.ingredients.apply', $this->rm), [
            'changes' => [
                ['id' => $this->acb->id, 'code' => 'ACB-01', 'name' => 'Acne Buster'],
                // A deleted item still holds its code.
                ['id' => $this->argan->id, 'code' => 'OLD-1', 'name' => 'Argan Oil'],
            ],
        ])->assertRedirect();

        $this->assertSame('error', Inertia::getFlashed()['toast']['type'] ?? null);
        $this->assertStringContainsString('Nothing was changed', Inertia::getFlashed()['toast']['message']);
        $this->assertSame('ACB', $this->acb->refresh()->code);
        $this->assertSame('AGO', $this->argan->refresh()->code);
    }

    #[Test]
    public function only_someone_who_may_edit_raw_materials_can_upload(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole(RoleName::Viewer->value);

        $this->actingAs($viewer)->post(route('stores.ingredients.read', $this->rm), ['sheet' => $this->sheet([])])->assertForbidden();
        $this->actingAs($viewer)->post(route('stores.ingredients.apply', $this->rm), [
            'changes' => [['id' => $this->acb->id, 'code' => 'X', 'name' => 'Y']],
        ])->assertForbidden();
        $this->assertSame('ACB', $this->acb->refresh()->code);
        $this->assertTrue(Item::query()->where('code', 'ACB')->exists());
    }

    #[Test]
    public function a_sheet_that_is_not_the_list_is_refused(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['Item code', 'Item name'], ['ACB', 'Acne']], null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'bad').'.xlsx';
        (new Xlsx($book))->save($path);

        $this->actingAs($this->admin)->post(route('stores.ingredients.read', $this->rm), [
            'sheet' => new UploadedFile($path, 'other.xlsx', null, null, true),
        ])->assertStatus(422)->assertJsonFragment(['message' => 'This is not the ingredient list from this screen: its first columns must be "ERP ID", "Code" and "Name". Download the list again and edit that.']);
    }
}
