<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Inventory\Services\StockTransferService;
use App\Domain\Marketplace\Models\Brand;
use App\Domain\MasterData\Models\Product;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\EmployeeAssignment;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Rail + Search shell: the places a person may open, the numbers
 * beside their pages, and Ctrl K — each showing only what that person
 * may see.
 */
class PlacesAndSearchTest extends TestCase
{
    use RefreshDatabase;

    private Facility $rudrapur;

    private Facility $depot;

    private Warehouse $rudrapurFg;

    private Warehouse $depotFg;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->rudrapur = Facility::factory()->manufacturing()->create(['code' => 'FAC-RDP-001', 'name' => 'Rudrapur Manufacturing Facility']);
        $this->depot = Facility::factory()->create(['code' => 'FAC-PM-001', 'name' => 'Paper Market Warehouse', 'can_manufacture' => false]);
        $this->rudrapurFg = Warehouse::factory()->atFacility($this->rudrapur)->ofType(WarehouseType::FinishedGoods)->create(['code' => 'RDP-FG']);
        $this->depotFg = Warehouse::factory()->atFacility($this->depot)->ofType(WarehouseType::FinishedGoods)->create(['code' => 'PM-FG']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(RoleName::SuperAdmin->value);
    }

    #[Test]
    public function the_places_come_from_the_facilities_a_person_works_at(): void
    {
        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('nav.places.0', ['key' => 'f'.$this->rudrapur->id, 'kind' => 'factory', 'facility_id' => $this->rudrapur->id, 'name' => 'Rudrapur Manufacturing Facility', 'short' => 'Rudrapur'])
                ->where('nav.places.1.kind', 'depot')
                ->where('nav.places.1.short', 'Paper Market')
                ->where('nav.places.2.kind', 'contract')
                ->where('nav.places.3.kind', 'company')
                ->where('nav.default', 'f'.$this->rudrapur->id));

        // A depot storekeeper sees the depot, and no factory or contract work.
        $storekeeper = User::factory()->create();
        $storekeeper->assignRole(RoleName::StoreExecutive->value);
        EmployeeAssignment::factory()->create(['user_id' => $storekeeper->id, 'facility_id' => $this->depot->id, 'is_primary' => true]);

        $this->actingAs($storekeeper)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('nav.places', 2)
                ->where('nav.places.0.kind', 'depot')
                ->where('nav.places.1.kind', 'company')
                ->where('nav.default', 'f'.$this->depot->id));
    }

    #[Test]
    public function an_outside_agency_has_no_places_counts_or_search(): void
    {
        $agency = User::factory()->create();
        $agency->assignRole(RoleName::EcommerceAgency->value);
        $agency->brands()->attach(Brand::query()->updateOrCreate(['code' => 'RR'], ['name' => 'Rahat Rooh']));

        $this->actingAs($agency)->get(route('online-orders.index'))
            ->assertInertia(fn (Assert $page) => $page->where('nav.places', [])->where('nav.default', null));

        // Not on its allow-list: taken back to its labels.
        $this->actingAs($agency)->get(route('nav.search', ['q' => 'shampoo']))->assertRedirect(route('online-orders.index'));
        $this->actingAs($agency)->get(route('nav.counts', ['place' => 'f'.$this->depot->id]))->assertRedirect(route('online-orders.index'));
    }

    #[Test]
    public function the_depot_column_counts_the_lorry_on_its_way(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole(RoleName::Owner->value);
        $pcs = Uom::where('code', 'PCS')->firstOrFail();
        $product = Product::factory()->create(['stock_uom_id' => $pcs->id, 'requires_qc' => false]);
        $lot = InventoryLot::factory()->forItem($product)->create(['qc_status' => LotQcStatus::Approved]);
        app(InventoryLedgerService::class)->receive($product, $this->rudrapurFg, '100', $lot, userId: $owner->id);

        $transfers = app(StockTransferService::class);
        $transfer = $transfers->create(['source_warehouse_id' => $this->rudrapurFg->id, 'destination_warehouse_id' => $this->depotFg->id], [['item_id' => $product->id, 'quantity' => '40']], $owner->id);
        $transfers->dispatch($transfers->approve($transfer, $owner->id), $owner->id);

        $counts = $this->actingAs($this->admin)->getJson(route('nav.counts', ['place' => 'f'.$this->depot->id]))->assertOk()->json('counts');
        $this->assertSame(['n' => 1, 'tone' => 'info'], $counts['transfers.in'] ?? null);
        $this->assertSame(['n' => 1, 'tone' => 'info'], $counts['receive'] ?? null);

        // A place the person does not have answers with nothing.
        $this->actingAs($this->admin)->getJson(route('nav.counts', ['place' => 'f999']))->assertOk()->assertJsonPath('counts', []);
    }

    #[Test]
    public function search_finds_records_only_for_those_who_may_open_them(): void
    {
        $kg = Uom::where('code', 'KG')->firstOrFail();
        $sles = RawMaterial::factory()->create(['code' => 'SLES', 'name' => 'Sodium Lauryl Ether Sulphate', 'stock_uom_id' => $kg->id]);
        $product = Product::factory()->create(['name' => 'Satreetha Shampoo 500 ml']);
        InventoryLot::factory()->forItem($product)->create(['batch_number' => 'FG260927-041']);

        $this->actingAs($this->admin)->getJson(route('nav.search', ['q' => 'sles']))
            ->assertOk()
            ->assertJsonPath('groups.0.group', 'Materials and products')
            ->assertJsonPath('groups.0.items.0.title', 'SLES · Sodium Lauryl Ether Sulphate')
            ->assertJsonPath('groups.0.items.0.href', route('raw-materials.show', $sles));

        $this->actingAs($this->admin)->getJson(route('nav.search', ['q' => '260927-041']))
            ->assertJsonPath('groups.0.group', 'Batches');

        // A designer may not open raw materials or batches: none come back.
        $designer = User::factory()->create();
        $designer->assignRole(RoleName::Designer->value);
        $groups = $this->actingAs($designer)->getJson(route('nav.search', ['q' => 'sles']))->assertOk()->json('groups');
        $this->assertSame([], $groups);

        // One letter is not a search.
        $this->actingAs($this->admin)->getJson(route('nav.search', ['q' => 's']))->assertJsonPath('groups', []);
    }
}
