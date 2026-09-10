<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function a_factory_manager_sees_the_whole_floor(): void
    {
        $rm = Warehouse::factory()->create(['code' => 'WH-RM', 'type' => WarehouseType::RawMaterial]);
        Warehouse::factory()->create(['code' => 'WH-PM', 'type' => WarehouseType::Packaging]);

        $low = RawMaterial::factory()->create(['name' => 'Scarce Thing', 'minimum_stock' => '10', 'reorder_level' => '20']);
        $lot = InventoryLot::factory()->forItem($low)->create(['qc_status' => LotQcStatus::Approved, 'expiry_at' => now()->addDays(20)]);
        app(InventoryLedgerService::class)->receive($low, $rm, '5', $lot);

        $user = User::factory()->create(['name' => 'Suresh Patil']);
        $user->assignRole(RoleName::FactoryManager->value);

        $this->actingAs($user)->get(route('dashboard', ['days' => 7]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('dashboard')
                ->where('greeting.first_name', 'Suresh')
                ->where('period.days', 7)
                ->has('kpis', 6)
                ->where('kpis.4.key', 'critical')
                ->where('kpis.4.value', 1)
                ->has('stores', 3)
                ->where('stores.0.kind', 'raw_material')
                ->where('stores.0.counts.critical', 1)
                ->has('receiving', 7)
                ->has('output', 12)
                ->has('inProduction')
                ->has('attention', 1)
                ->where('attention.0.name', 'Scarce Thing')
                ->where('attention.0.level', 'critical')
                ->has('expiring', 1)
                ->where('expiring.0.batch_number', $lot->batch_number)
                ->has('upcoming')
                ->where('recentActivity', null)
                ->where('quickActions.plan', true)
                ->where('quickActions.receive', false)
            );
    }

    #[Test]
    public function a_designer_gets_only_a_greeting(): void
    {
        $user = User::factory()->create(['name' => 'Ritu Malhotra']);
        $user->assignRole(RoleName::Designer->value);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('dashboard')
                ->where('greeting.first_name', 'Ritu')
                ->has('kpis', 0)
                ->where('stores', null)
                ->where('attention', null)
                ->where('receiving', null)
                ->where('output', null)
                ->where('inProduction', null)
                ->where('upcoming', null)
                ->where('recentActivity', null)
                ->where('quickActions.plan', false)
            );
    }

    #[Test]
    public function an_unknown_period_falls_back_to_thirty_days(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::WarehouseManager->value);

        $this->actingAs($user)->get(route('dashboard', ['days' => 12]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('period.days', 30)->has('receiving', 30));
    }

    #[Test]
    public function the_stock_screen_opens_a_store_by_what_it_holds(): void
    {
        Warehouse::factory()->create(['code' => 'WH-RM', 'type' => WarehouseType::RawMaterial]);
        $pm = Warehouse::factory()->create(['code' => 'WH-PM', 'type' => WarehouseType::Packaging]);

        $user = User::factory()->create();
        $user->assignRole(RoleName::WarehouseManager->value);

        $this->actingAs($user)->get(route('stock.index', ['store' => 'packaging']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('selected.id', $pm->id));
    }
}
