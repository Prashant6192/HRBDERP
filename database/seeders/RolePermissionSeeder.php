<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Access\PermissionCatalogue;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates every permission in the catalogue and the sixteen built-in roles.
 *
 * Idempotent: re-running restores the default permission set of each built-in
 * role, which is the intended way to pick up newly added abilities.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $added = [];

        foreach (PermissionCatalogue::all() as $permission) {
            $created = Permission::firstOrCreate(['name' => $permission, 'guard_name' => $guard]);

            if ($created->wasRecentlyCreated) {
                $added[] = $permission;
            }
        }

        // A role that already exists may have been tuned by an administrator
        // on the Roles screen. It is not reset: it only gains the abilities
        // that are new to the catalogue and belong in its defaults.
        foreach (RoleName::all() as $roleName) {
            $role = Role::firstOrCreate(['name' => $roleName->value, 'guard_name' => $guard]);

            if ($role->wasRecentlyCreated || $role->permissions()->count() === 0) {
                $role->syncPermissions($roleName->permissions());

                continue;
            }

            $gains = array_values(array_intersect($roleName->permissions(), $added));

            if ($gains !== []) {
                $role->givePermissionTo($gains);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
