<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Domain\Access\Enums\RoleName;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A Warehouse Manager role set up on the live site before online orders
 * existed is given what it needs to run them.
 */
class DepotRolesOnlineOrdersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_older_warehouse_manager_role_gains_the_online_order_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $role = Role::findByName(RoleName::WarehouseManager->value);
        $role->revokePermissionTo(['marketplace.view', 'marketplace.print', 'marketplace.pack', 'marketplace.handover', 'marketplace.return']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($role->fresh()->hasPermissionTo('marketplace.view'));

        $migration = require database_path('migrations/2026_09_29_110000_grant_depot_roles_their_online_order_permissions.php');
        $migration->up();

        $role = Role::findByName(RoleName::WarehouseManager->value);
        foreach (['marketplace.view', 'marketplace.print', 'marketplace.pack', 'marketplace.handover', 'marketplace.return'] as $p) {
            $this->assertTrue($role->hasPermissionTo($p), $p);
        }
        $this->assertFalse($role->hasPermissionTo('marketplace.upload'), 'Uploads stay with the agency and the office.');
    }

    #[Test]
    public function a_warehouse_manager_role_with_only_the_labels_gains_its_stock_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $role = Role::findByName(RoleName::WarehouseManager->value);
        $role->syncPermissions(['marketplace.view', 'marketplace.print']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $migration = require database_path('migrations/2026_09_29_120000_give_depot_roles_their_full_default_permissions.php');
        $migration->up();

        $role = Role::findByName(RoleName::WarehouseManager->value);
        foreach (['inventory.view', 'inventory.receive_transfer', 'inventory.transfer', 'inventory.count', 'marketplace.pack', 'marketplace.handover'] as $p) {
            $this->assertTrue($role->hasPermissionTo($p), $p);
        }
    }
}
