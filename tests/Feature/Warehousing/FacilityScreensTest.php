<?php

declare(strict_types=1);

namespace Tests\Feature\Warehousing;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\MasterData\Models\Product;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\EmployeeAssignment;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\FacilityType;
use App\Domain\Warehousing\Models\StoreCategory;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The facility screens end to end: the wizard, the facility page, adding a
 * store, assigning people, opening stock, transfers — and the doors that
 * stay shut.
 */
class FacilityScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $delhiOnly;

    private Facility $rudrapur;

    private Facility $delhi;

    private Warehouse $rudrapurFg;

    private Warehouse $delhiFg;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->owner->assignRole(RoleName::Owner->value);

        $this->rudrapur = Facility::factory()->manufacturing()->create(['code' => 'FAC-RDP-001', 'name' => 'Rudrapur Manufacturing Facility', 'city' => 'Rudrapur']);
        Warehouse::factory()->atFacility($this->rudrapur)->ofType(WarehouseType::RawMaterial)->create(['code' => 'RDP-RM']);
        $this->rudrapurFg = Warehouse::factory()->atFacility($this->rudrapur)->ofType(WarehouseType::FinishedGoods)->create(['code' => 'RDP-FG']);

        $this->delhi = Facility::factory()->create(['code' => 'FAC-DEL-001', 'name' => 'Delhi Warehouse', 'city' => 'Delhi']);
        $this->delhiFg = Warehouse::factory()->atFacility($this->delhi)->ofType(WarehouseType::FinishedGoods)->create(['code' => 'DEL-FG']);

        $this->delhiOnly = User::factory()->create();
        $this->delhiOnly->assignRole(RoleName::WarehouseManager->value);
        EmployeeAssignment::factory()->create(['user_id' => $this->delhiOnly->id, 'facility_id' => $this->delhi->id]);

        $pcs = Uom::where('code', 'PCS')->firstOrFail();
        $this->product = Product::factory()->create(['name' => 'Face Wash 100 ml', 'stock_uom_id' => $pcs->id, 'requires_qc' => false]);
    }

    #[Test]
    public function the_facilities_list_shows_stores_people_and_capabilities(): void
    {
        $this->actingAs($this->owner)->get(route('facilities.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('facilities/index')
                ->has('facilities.data', 2)
                ->where('facilities.data.0.code', 'FAC-DEL-001')
                ->where('facilities.data.0.can_manufacture', false)
                ->has('facilities.data.0.stores', 1)
                ->where('facilities.data.1.code', 'FAC-RDP-001')
                ->where('facilities.data.1.can_manufacture', true)
                ->has('facilities.data.1.stores', 2)
                ->where('facilities.data.1.capabilities', fn ($caps) => collect($caps)->pluck('badge')->contains('MFG')));
    }

    #[Test]
    public function the_wizard_creates_a_facility_with_its_stores_and_people(): void
    {
        $categories = StoreCategory::query()->pluck('id', 'code');
        $storekeeper = User::factory()->create();

        $response = $this->actingAs($this->owner)->post(route('facilities.store'), [
            'name' => 'Mumbai Depot',
            'code' => '',
            'facility_type_id' => FacilityType::query()->where('code', 'DEPOT')->value('id'),
            'city' => 'Mumbai',
            'state' => 'Maharashtra',
            'can_store' => true, 'can_receive' => true, 'can_dispatch' => true, 'can_manufacture' => false,
            'stores' => [
                ['store_category_id' => $categories['FG'], 'name' => 'Mumbai FG', 'code' => '', 'default_location' => 'Rack A'],
                ['store_category_id' => $categories['RET'], 'name' => '', 'code' => 'MUM-RET'],
            ],
            'employees' => [
                ['user_id' => $storekeeper->id, 'is_primary' => true, 'designation' => 'Depot Keeper'],
            ],
            'opening_stock' => 'now',
        ]);

        $facility = Facility::query()->where('name', 'Mumbai Depot')->firstOrFail();

        $response->assertRedirect(route('facilities.opening-stock.create', $facility));
        $this->assertSame('FAC-MUM-001', $facility->code);
        $this->assertFalse($facility->can_manufacture);
        $this->assertEqualsCanonicalizing(['MUM-FG', 'MUM-RET'], $facility->stores()->pluck('code')->all());
        $this->assertSame(WarehouseType::Returns, $facility->stores()->where('code', 'MUM-RET')->first()->type);
        $this->assertDatabaseHas('employee_assignments', ['user_id' => $storekeeper->id, 'facility_id' => $facility->id, 'is_primary' => true, 'designation' => 'Depot Keeper']);
        $this->assertDatabaseHas('warehouse_locations', ['code' => 'RACK-A']);
    }

    #[Test]
    public function every_facility_tab_renders_and_production_is_hidden_without_manufacturing(): void
    {
        foreach (['overview', 'stores', 'inventory', 'employees', 'transfers', 'incoming', 'dispatch', 'production', 'activity', 'settings'] as $tab) {
            $this->actingAs($this->owner)->get(route('facilities.show', [$this->rudrapur, 'tab' => $tab]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('facilities/show')->where('tab', $tab));
        }

        $this->actingAs($this->owner)->get(route('facilities.show', [$this->delhi, 'tab' => 'production']))
            ->assertInertia(fn (Assert $page) => $page->where('tab', 'overview')->where('tabs', fn ($tabs) => ! collect($tabs)->contains('production')));
    }

    #[Test]
    public function a_store_can_be_added_to_a_live_facility_from_its_page(): void
    {
        $this->actingAs($this->owner)->post(route('facilities.stores.store', $this->rudrapur), [
            'store_category_id' => StoreCategory::query()->where('code', 'SMPL')->value('id'),
            'name' => 'Samples Store',
        ])->assertRedirect();

        $this->assertDatabaseHas('warehouses', ['facility_id' => $this->rudrapur->id, 'code' => 'RDP-SMPL', 'type' => 'samples']);
    }

    #[Test]
    public function an_employee_can_be_assigned_to_a_store_and_the_assignment_ended(): void
    {
        $person = User::factory()->create();

        $this->actingAs($this->owner)->post(route('facilities.employees.store', $this->rudrapur), [
            'user_id' => $person->id, 'store_id' => $this->rudrapurFg->id, 'designation' => 'FG Store Keeper',
        ])->assertRedirect();

        $assignment = EmployeeAssignment::query()->where('user_id', $person->id)->firstOrFail();
        $this->assertSame($this->rudrapurFg->id, $assignment->store_id);
        $this->assertTrue($assignment->is_primary, 'The first assignment is the primary one.');

        // A store at another facility is refused.
        $this->actingAs($this->owner)->post(route('facilities.employees.store', $this->rudrapur), [
            'user_id' => $person->id, 'store_id' => $this->delhiFg->id,
        ])->assertSessionHasErrors('store_id');

        $this->actingAs($this->owner)->delete(route('employee-assignments.destroy', $assignment))->assertRedirect();
        $this->assertSame('ended', $assignment->fresh()->status->value);
    }

    #[Test]
    public function a_delhi_only_employee_cannot_book_stock_into_rudrapur(): void
    {
        $this->actingAs($this->delhiOnly)->post(route('facilities.opening-stock.store', $this->rudrapur), [
            'warehouse_id' => $this->rudrapurFg->id,
            'lines' => [['item_id' => $this->product->id, 'quantity' => '5']],
        ])->assertForbidden();

        $this->assertDatabaseCount('inventory_transactions', 0);

        // Their own facility is fine.
        $this->actingAs($this->delhiOnly)->post(route('facilities.opening-stock.store', $this->delhi), [
            'warehouse_id' => $this->delhiFg->id,
            'lines' => [['item_id' => $this->product->id, 'quantity' => '5', 'batch_number' => 'DEL-OPEN-1']],
        ])->assertRedirect(route('stores.show', $this->delhiFg));

        $this->assertSame('5.000000', app(StockBalanceService::class)->onHand($this->product, $this->delhiFg)->__toString());
    }

    #[Test]
    public function a_delhi_only_employee_cannot_dispatch_from_rudrapur_but_can_receive_at_delhi(): void
    {
        $lot = InventoryLot::factory()->forItem($this->product)->create(['qc_status' => LotQcStatus::Approved]);
        app(InventoryLedgerService::class)->receive($this->product, $this->rudrapurFg, '50', $lot, userId: $this->owner->id);

        $this->actingAs($this->owner)->post(route('transfers.store'), [
            'source_warehouse_id' => $this->rudrapurFg->id, 'destination_warehouse_id' => $this->delhiFg->id, 'submit' => 'request',
            'lines' => [['item_id' => $this->product->id, 'quantity' => '20']],
        ])->assertRedirect();

        $transfer = StockTransfer::query()->firstOrFail();

        $this->actingAs($this->owner)->post(route('transfers.approve', $transfer))->assertRedirect();
        $this->actingAs($this->delhiOnly)->post(route('transfers.dispatch', $transfer))->assertForbidden();
        $this->actingAs($this->owner)->post(route('transfers.dispatch', $transfer))->assertRedirect();

        $line = $transfer->lines()->firstOrFail();
        $this->actingAs($this->delhiOnly)->post(route('transfers.receive', $transfer), [
            'lines' => [['line_id' => $line->id, 'quantity' => '20']],
        ])->assertRedirect();

        $this->assertSame('received', $transfer->fresh()->status->value);
        $this->assertSame('20.000000', app(StockBalanceService::class)->onHand($this->product, $this->delhiFg)->__toString());

        $this->actingAs($this->owner)->get(route('transfers.show', $transfer))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('transfers/show')->where('transfer.status', 'received')->has('lines', 1));
    }

    #[Test]
    public function the_store_page_refuses_to_delete_a_used_store(): void
    {
        $material = RawMaterial::factory()->create(['stock_uom_id' => Uom::where('code', 'KG')->value('id'), 'reorder_level' => null, 'minimum_stock' => null]);
        $rm = Warehouse::query()->where('code', 'RDP-RM')->firstOrFail();
        app(InventoryLedgerService::class)->receive($material, $rm, '3', InventoryLot::factory()->forItem($material)->create(['qc_status' => LotQcStatus::Approved]), userId: $this->owner->id);

        $this->actingAs($this->owner)->get(route('stores.show', $rm))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('stores/show')->where('store.has_history', true)->has('rows', 1));

        $this->actingAs($this->owner)->delete(route('stores.destroy', $rm))->assertRedirect();
        $this->assertNotSoftDeleted('warehouses', ['id' => $rm->id]);
        $this->assertStringContainsString('cannot be deleted because operational history exists', session('toast')['message'] ?? json_encode(session()->all()));
    }

    #[Test]
    public function the_masters_are_editable_but_never_lose_a_category_in_use(): void
    {
        $this->actingAs($this->owner)->get(route('store-categories.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('settings/store-categories')->has('categories', 13));

        $this->actingAs($this->owner)->post(route('store-categories.store'), [
            'code' => 'COLD', 'name' => 'Cold Room', 'badge' => 'COLD', 'kind' => 'raw_material',
        ])->assertRedirect();
        $this->assertDatabaseHas('store_categories', ['code' => 'COLD', 'kind' => 'raw_material', 'is_system' => false]);

        $rm = StoreCategory::query()->where('code', 'RM')->firstOrFail();
        $this->actingAs($this->owner)->put(route('store-categories.update', $rm), [
            'code' => 'RM', 'name' => 'Raw Materials', 'badge' => 'RM', 'kind' => 'general',
        ])->assertSessionHasErrors('kind');

        $this->actingAs($this->owner)->put(route('store-categories.update', $rm), [
            'code' => 'RM', 'name' => 'Raw Materials', 'badge' => 'RM',
        ])->assertRedirect();
        $this->assertSame('Raw Materials', $rm->fresh()->name);

        $this->actingAs($this->owner)->get(route('facility-types.index'))->assertOk();
        $this->actingAs($this->delhiOnly)->get(route('store-categories.index'))->assertForbidden();
    }

    #[Test]
    public function the_dashboard_and_stock_screens_take_a_facility_filter(): void
    {
        $this->actingAs($this->owner)->get(route('dashboard', ['facility' => $this->delhi->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('facility.code', 'FAC-DEL-001')
                ->where('output', null)
                ->where('inProduction', null));

        $this->actingAs($this->owner)->get(route('stock.index', ['facility' => $this->delhi->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('facility', $this->delhi->id)->where('selected.code', 'DEL-FG'));

        // Someone confined to Delhi only sees Delhi in the picker.
        $this->actingAs($this->delhiOnly)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('facilities', 1)->where('facilities.0.code', 'FAC-DEL-001'));
    }
}
