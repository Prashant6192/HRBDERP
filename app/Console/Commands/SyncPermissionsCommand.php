<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Access\PermissionCatalogue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Brings the permission and role tables into line with the code.
 *
 * Run after adding a module or ability to PermissionCatalogue. It is safe to
 * run repeatedly and on a live system: permissions are created but never
 * dropped, so a permission removed from the catalogue is reported rather than
 * deleted out from under whoever currently holds it. A permission that is new
 * to the catalogue is handed to the built-in roles whose defaults include it;
 * nothing else about a role is touched unless --roles is given.
 */
class SyncPermissionsCommand extends Command
{
    protected $signature = 'erp:sync-permissions
                            {--roles : Also reset each built-in role to its default permissions}
                            {--prune : Delete permissions that are no longer in the catalogue}';

    protected $description = 'Synchronise the permission table with the ERP permission catalogue';

    public function handle(): int
    {
        $guard = config('auth.defaults.guard', 'web');

        $defined = PermissionCatalogue::all();
        $existing = Permission::where('guard_name', $guard)->pluck('name')->all();

        $missing = array_values(array_diff($defined, $existing));
        $orphaned = array_values(array_diff($existing, $defined));

        DB::transaction(function () use ($missing, $orphaned, $guard): void {
            foreach ($missing as $name) {
                Permission::create(['name' => $name, 'guard_name' => $guard]);
            }

            if ($this->option('prune') && $orphaned !== []) {
                Permission::where('guard_name', $guard)
                    ->whereIn('name', $orphaned)
                    ->delete();
            }

            if ($this->option('roles')) {
                $this->syncRoles($guard);
            } elseif ($missing !== []) {
                $this->grantNewPermissions($guard, $missing);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->components->info(sprintf(
            '%d permission(s) created, %d already present.',
            count($missing),
            count($defined) - count($missing),
        ));

        if ($orphaned !== []) {
            if ($this->option('prune')) {
                $this->components->warn(sprintf('%d obsolete permission(s) removed.', count($orphaned)));
            } else {
                $this->components->warn(sprintf(
                    '%d permission(s) exist in the database but not in the catalogue: %s',
                    count($orphaned),
                    implode(', ', $orphaned),
                ));
                $this->line('  Re-run with --prune to remove them.');
            }
        }

        return self::SUCCESS;
    }

    /**
     * Give each built-in role the abilities that are new to the catalogue and
     * belong in its defaults — without disturbing whatever else an
     * administrator has granted or withdrawn on the Roles screen.
     *
     * @param  list<string>  $added
     */
    private function grantNewPermissions(string $guard, array $added): void
    {
        foreach (RoleName::all() as $roleName) {
            $role = Role::firstOrCreate(['name' => $roleName->value, 'guard_name' => $guard]);
            $gains = array_values(array_intersect($roleName->permissions(), $added));

            if ($gains === []) {
                continue;
            }

            $role->givePermissionTo($gains);
            $this->components->twoColumnDetail($roleName->value, 'gains '.implode(', ', $gains));
        }
    }

    /**
     * Reset every built-in role to the permissions its definition declares.
     *
     * This overwrites permission changes an administrator made through the
     * Roles screen, which is why it is behind an explicit flag.
     */
    private function syncRoles(string $guard): void
    {
        foreach (RoleName::all() as $roleName) {
            $role = Role::firstOrCreate([
                'name' => $roleName->value,
                'guard_name' => $guard,
            ]);

            $role->syncPermissions($roleName->permissions());

            $this->components->twoColumnDetail(
                $roleName->value,
                sprintf('%d permissions', count($roleName->permissions())),
            );
        }
    }
}
