<?php

declare(strict_types=1);

use App\Domain\Access\Enums\RoleName;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The depot's Warehouse Manager saw only the online-order labels on the
 * live site: the role there lacked its stock permissions (receiving,
 * transfers, counts, the finished goods store). The Warehouse Manager and
 * Store Executive roles are given every permission their defaults carry.
 * Only adds; nothing an administrator granted is taken away.
 */
return new class extends Migration
{
    public function up(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        foreach ([RoleName::WarehouseManager, RoleName::StoreExecutive] as $name) {
            $role = Role::query()->where('name', $name->value)->where('guard_name', $guard)->first();

            if ($role === null) {
                continue;
            }

            $wanted = $name->permissions();

            foreach ($wanted as $permission) {
                Permission::findOrCreate($permission, $guard);
            }

            $role->givePermissionTo($wanted);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Permissions granted here stay; they are managed on the Roles screen.
    }
};
