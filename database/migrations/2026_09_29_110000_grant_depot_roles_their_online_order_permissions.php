<?php

declare(strict_types=1);

use App\Domain\Access\Enums\RoleName;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The depot runs the day's online orders: the Warehouse Manager prints,
 * packs and hands over, the Store Executive packs and hands over. On the
 * live site those roles may predate the online-order permissions, so
 * they are given them here. Only adds; nothing is taken away.
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

            $wanted = array_values(array_filter($name->permissions(), fn (string $p) => str_starts_with($p, 'marketplace.')));

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
