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

        foreach (PermissionCatalogue::all() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => $guard]);
        }

        foreach (RoleName::all() as $roleName) {
            $role = Role::firstOrCreate(['name' => $roleName->value, 'guard_name' => $guard]);
            $role->syncPermissions($roleName->permissions());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
