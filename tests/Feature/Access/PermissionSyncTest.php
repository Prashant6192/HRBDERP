<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Access\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Deploys re-run the seeder and the sync command. Neither may undo what an
 * administrator did on the Roles screen; both must still deliver abilities
 * that are new to the catalogue.
 */
class PermissionSyncTest extends TestCase
{
    use RefreshDatabase;

    private function role(RoleName $name): Role
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return Role::where('name', $name->value)->firstOrFail();
    }

    #[Test]
    public function reseeding_keeps_an_administrators_changes(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $viewer = $this->role(RoleName::Viewer);
        $viewer->revokePermissionTo('inventory.view');
        $viewer->givePermissionTo('formula.view');

        $this->seed(RolePermissionSeeder::class);

        $viewer = $this->role(RoleName::Viewer);
        $this->assertFalse($viewer->hasPermissionTo('inventory.view'), 'A withdrawn ability stays withdrawn');
        $this->assertTrue($viewer->hasPermissionTo('formula.view'), 'A granted ability stays granted');
    }

    #[Test]
    public function an_ability_new_to_the_catalogue_reaches_the_roles_that_should_have_it(): void
    {
        $this->seed(RolePermissionSeeder::class);

        // Pretend planning.export was added to the catalogue after the roles
        // were set up: remove the permission row entirely.
        Permission::where('name', 'planning.export')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($this->role(RoleName::ProductionManager)->hasPermissionTo('planning.view') === false);

        $this->artisan('erp:sync-permissions')->assertSuccessful();

        $this->assertTrue($this->role(RoleName::ProductionManager)->hasPermissionTo('planning.export'));
        $this->assertTrue($this->role(RoleName::FactoryManager)->hasPermissionTo('planning.export'));
        $this->assertFalse($this->role(RoleName::Viewer)->hasPermissionTo('planning.export'));
    }

    #[Test]
    public function the_roles_flag_still_resets_a_role_to_its_defaults(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->role(RoleName::Viewer)->givePermissionTo('formula.view');

        $this->artisan('erp:sync-permissions', ['--roles' => true])->assertSuccessful();

        $this->assertFalse($this->role(RoleName::Viewer)->hasPermissionTo('formula.view'));
    }
}
