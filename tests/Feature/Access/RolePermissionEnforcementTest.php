<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Domain\Access\Enums\RoleName;
use App\Domain\MasterData\Models\PackagingMaterial;
use App\Domain\MasterData\Models\Product;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Permissions have to bite on the server.
 *
 * These exercise the policies directly, which is what controllers authorise
 * against, so that a permission change is caught here rather than by someone
 * discovering they can edit a formula.
 */
class RolePermissionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function userWithRole(RoleName $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user->fresh();
    }

    #[Test]
    public function a_super_admin_passes_every_check(): void
    {
        $user = $this->userWithRole(RoleName::SuperAdmin);

        $this->assertTrue($user->can('formula.approve'));
        $this->assertTrue($user->can('inventory.adjust'));
        $this->assertTrue($user->can('user.delete'));

        // Including an ability nobody was explicitly granted.
        $this->assertTrue($user->can('marketplace.reconcile'));
    }

    #[Test]
    public function the_super_admin_bypass_does_not_leak_to_other_roles(): void
    {
        $viewer = $this->userWithRole(RoleName::Viewer);

        $this->assertFalse($viewer->can('inventory.adjust'));
        $this->assertFalse($viewer->can('formula.view'));
        $this->assertFalse($viewer->can('user.delete'));
        $this->assertTrue($viewer->can('inventory.view'));
    }

    #[Test]
    public function a_designer_may_read_packaging_but_not_raw_materials(): void
    {
        $designer = $this->userWithRole(RoleName::Designer);

        $packaging = PackagingMaterial::factory()->create();
        $rawMaterial = RawMaterial::factory()->create();

        $this->assertTrue($designer->can('view', $packaging));
        $this->assertFalse($designer->can('view', $rawMaterial));
    }

    #[Test]
    public function the_item_policy_picks_the_module_from_the_item_type(): void
    {
        // One policy, one table, three different answers — this is the case
        // that a single shared items table has to get right.
        $purchaseManager = $this->userWithRole(RoleName::PurchaseManager);

        $rawMaterial = RawMaterial::factory()->create();
        $product = Product::factory()->create();

        $this->assertTrue($purchaseManager->can('view', $rawMaterial));
        $this->assertTrue($purchaseManager->can('view', $product));

        // A purchase manager reads the product master but does not maintain it.
        $this->assertFalse($purchaseManager->can('update', $product));
    }

    #[Test]
    public function a_warehouse_manager_may_adjust_stock_and_a_production_manager_may_not(): void
    {
        $warehouseManager = $this->userWithRole(RoleName::WarehouseManager);
        $productionManager = $this->userWithRole(RoleName::ProductionManager);

        $this->assertTrue($warehouseManager->can('inventory.adjust'));
        $this->assertFalse($productionManager->can('inventory.adjust'));

        // The production manager still consumes against an order.
        $this->assertTrue($productionManager->can('inventory.consume'));
    }

    #[Test]
    public function warehouse_maintenance_is_limited_to_the_roles_that_own_it(): void
    {
        $warehouseManager = $this->userWithRole(RoleName::WarehouseManager);
        $salesManager = $this->userWithRole(RoleName::SalesManager);

        $warehouse = Warehouse::factory()->create();

        $this->assertTrue($warehouseManager->can('update', $warehouse));
        $this->assertFalse($salesManager->can('view', $warehouse));
    }

    #[Test]
    public function nobody_may_delete_their_own_account(): void
    {
        $superAdmin = $this->userWithRole(RoleName::SuperAdmin);

        // Even the bypass does not apply: the policy is not consulted for a
        // Super Admin, so this asserts the rule the UserPolicy encodes for
        // everyone else.
        $other = $this->userWithRole(RoleName::Owner);

        $this->assertFalse($other->can('delete', $other));
        $this->assertTrue($superAdmin->can('delete', $other));
    }

    #[Test]
    public function only_a_super_admin_may_assign_roles(): void
    {
        $superAdmin = $this->userWithRole(RoleName::SuperAdmin);
        $owner = $this->userWithRole(RoleName::Owner);
        $target = $this->userWithRole(RoleName::Viewer);

        $this->assertTrue($superAdmin->can('assignRoles', $target));
        $this->assertFalse($owner->can('assignRoles', $target));
    }

    #[Test]
    public function a_user_may_always_read_their_own_profile(): void
    {
        $designer = $this->userWithRole(RoleName::Designer);

        $this->assertFalse($designer->can('user.view'));
        $this->assertTrue($designer->can('view', $designer));
    }

    #[Test]
    public function nobody_may_permanently_destroy_a_record(): void
    {
        $owner = $this->userWithRole(RoleName::Owner);
        $warehouse = Warehouse::factory()->create();

        $this->assertFalse($owner->can('forceDelete', $warehouse));
    }
}
