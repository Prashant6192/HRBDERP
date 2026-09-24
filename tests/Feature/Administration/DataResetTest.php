<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Administration\Services\DataResetService;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\InventoryTransaction;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\MasterData\Models\Item;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Planning\Models\ProductionPlan;
use App\Domain\Procurement\Models\Vendor;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Issue #12: clearing what testing left behind, without losing the factory
 * itself, and filling the system with a worked example.
 */
class DataResetTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        config(['erp.company.name' => 'HRBD']);

        $this->admin = User::factory()->create(['name' => 'Prashant']);
        $this->admin->assignRole(RoleName::SuperAdmin->value);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(RoleName::Owner->value);
    }

    private function stockedFactory(): array
    {
        $facility = Facility::factory()->manufacturing()
            ->withStores([WarehouseType::RawMaterial, WarehouseType::Packaging, WarehouseType::FinishedGoods, WarehouseType::Quarantine])
            ->create(['name' => 'Rudrapur Factory']);
        $store = $facility->stores()->where('type', WarehouseType::RawMaterial->value)->firstOrFail();

        $kg = Uom::where('code', 'KG')->sole();
        $item = RawMaterial::factory()->create(['stock_uom_id' => $kg->id]);
        $lot = InventoryLot::factory()->forItem($item)->create();
        app(InventoryLedgerService::class)->receive($item, $store, '100', $lot, userId: $this->admin->id);

        Vendor::factory()->create();

        return [$facility, $store, $item];
    }

    #[Test]
    public function only_the_system_administrator_reaches_the_screen(): void
    {
        $this->actingAs($this->owner)->get(route('administration.data'))->assertForbidden();
        $this->actingAs($this->owner)->post(route('administration.data.clear'), [])->assertForbidden();
        $this->actingAs($this->owner)->post(route('administration.data.demo'), [])->assertForbidden();

        $this->stockedFactory();

        $this->actingAs($this->admin)->get(route('administration.data'))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('administration/data')
                ->where('company', 'HRBD')
                ->has('scopes', 10)
                ->where('counts.stock', 1)
                ->where('counts.partners', 1));
    }

    #[Test]
    public function nothing_is_cleared_without_the_company_name_typed_out(): void
    {
        $this->stockedFactory();

        $this->actingAs($this->admin)->post(route('administration.data.clear'), [
            'scopes' => ['stock'],
            'confirmation' => 'hrbd',
        ])->assertSessionHasErrors('confirmation');

        $this->assertSame(1, InventoryLot::count(), 'Still there.');

        $this->actingAs($this->admin)->post(route('administration.data.clear'), [
            'scopes' => [],
            'confirmation' => 'HRBD',
        ])->assertSessionHasErrors('scopes');
    }

    #[Test]
    public function clearing_stock_leaves_the_stores_materials_and_people_alone(): void
    {
        [$facility, $store, $item] = $this->stockedFactory();

        $this->actingAs($this->admin)->post(route('administration.data.clear'), [
            'scopes' => ['stock'],
            'confirmation' => 'HRBD',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, InventoryLot::count());
        $this->assertSame(0, InventoryTransaction::count(), 'Ledger postings go with the stock they made.');
        $this->assertSame(0, DB::table('stock_balances')->count());

        // What the factory is does not change because a trial did.
        $this->assertModelExists($facility);
        $this->assertModelExists($store);
        $this->assertModelExists($item);
        $this->assertSame(1, Vendor::count());
        $this->assertSame(2, User::count());
        $this->assertGreaterThan(0, DB::table('uoms')->count());
        $this->assertGreaterThan(0, DB::table('roles')->count());
    }

    #[Test]
    public function clearing_the_materials_pulls_in_everything_built_on_them(): void
    {
        $reset = app(DataResetService::class);

        $this->assertSame(
            ['production', 'dispatch', 'online_orders', 'purchasing', 'stock', 'formulas', 'materials'],
            $reset->withDependencies(['materials']),
        );
        $this->assertSame(['production', 'dispatch', 'online_orders', 'purchasing', 'stock', 'formulas'], $reset->addedByDependency(['materials']));

        // Clearing the batches drags in the deliveries and dispatches that point at them.
        $this->assertSame(['dispatch', 'purchasing', 'stock'], $reset->withDependencies(['stock']));

        // Facilities are the deepest, and are never pulled in by anything else.
        $this->assertNotContains('facilities', $reset->withDependencies(['materials']));
    }

    #[Test]
    public function the_worked_example_fills_the_whole_factory_and_can_then_be_cleared(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        $this->actingAs($this->admin)->post(route('administration.data.demo'), [
            'confirmation' => 'HRBD',
        ])->assertRedirect()->assertSessionHasNoErrors();

        // A factory anyone can walk end to end.
        $this->assertGreaterThan(0, Facility::count());
        $this->assertGreaterThanOrEqual(4, Warehouse::query()->where('is_system', false)->count());
        $this->assertGreaterThanOrEqual(6, Item::count());
        $this->assertSame(1, Formula::count());
        $this->assertNotNull(Formula::sole()->activeVersion, 'The recipe is live, so a batch can be planned.');
        $this->assertGreaterThan(0, InventoryLot::count());
        $this->assertSame(1, ProductionPlan::count());
        $this->assertSame(1, ManufacturingOrder::count());
        $this->assertSame('completed', ManufacturingOrder::sole()->status->value);
        $this->assertSame(2, Vendor::count());

        // No login was created on a live system.
        $this->assertSame(2, User::count());

        // And it all comes out again, leaving the plant standing.
        $this->actingAs($this->admin)->post(route('administration.data.clear'), [
            'scopes' => ['materials', 'partners', 'workflow'],
            'restart_numbering' => true,
            'confirmation' => 'HRBD',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, Item::count());
        $this->assertSame(0, Formula::count());
        $this->assertSame(0, InventoryLot::count());
        $this->assertSame(0, ManufacturingOrder::count());
        $this->assertSame(0, Vendor::count());
        $this->assertGreaterThan(0, Warehouse::count(), 'The stores stay, as asked.');
        $this->assertSame(0, DB::table('document_sequences')->count());
    }

    #[Test]
    public function the_console_lists_the_scopes_and_clears_only_what_it_is_told(): void
    {
        $this->stockedFactory();

        $this->artisan('erp:reset')
            ->expectsOutputToContain('Nothing was cleared')
            ->assertSuccessful();

        $this->assertSame(1, InventoryLot::count());

        $this->artisan('erp:reset', ['--scope' => ['stock'], '--force' => true])
            ->assertSuccessful();

        $this->assertSame(0, InventoryLot::count());
        $this->assertSame(1, Vendor::count(), 'Only what was asked for.');
    }
}
