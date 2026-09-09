<?php

declare(strict_types=1);

namespace App\Http\Controllers\Administration;

use App\Domain\Access\Models\Role;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles and the permissions attached to them.
 *
 * Changing a role changes what a group of people can do, so every change is
 * audited with the full before and after permission sets.
 */
class RoleController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
        //
    }

    public function index(Request $request): Response
    {
        Gate::authorize('role.view');

        $roles = Role::query()
            ->withCount(['users', 'permissions'])
            ->orderBy('name')
            ->get()
            ->map(static fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description(),
                'users_count' => $role->users_count,
                'permissions_count' => $role->permissions_count,
                'is_built_in' => $role->isBuiltIn(),
            ]);

        return Inertia::render('roles/index', [
            'roles' => $roles,
            'can' => ['update' => $request->user()->can('role.edit')],
        ]);
    }

    public function edit(Role $role): Response
    {
        Gate::authorize('role.edit');

        return Inertia::render('roles/edit', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description(),
                'is_super_admin' => $role->isSuperAdmin(),
            ],
            'assigned' => $role->permissions->pluck('name')->values()->all(),
            'catalogue' => $this->catalogue(),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        Gate::authorize('role.edit');

        $validated = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(PermissionCatalogue::all())],
        ]);

        $before = $role->permissions->pluck('name')->sort()->values()->all();
        $after = collect($validated['permissions'] ?? [])->sort()->values()->all();

        if ($before === $after) {
            return to_route('roles.index');
        }

        $role->syncPermissions($after);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->auditLogger->log(
            action: AuditAction::PermissionsChanged,
            entity: $role,
            oldValues: ['permissions' => $before],
            newValues: ['permissions' => $after],
            description: "Permissions changed for role {$role->name}.",
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Permissions updated for {$role->name}.",
        ]);

        return to_route('roles.index');
    }

    /**
     * The permission catalogue arranged for the role editor: grouped by
     * navigation group, then by module, with a label per ability.
     *
     * @return list<array{group: string, modules: list<array{key: string, label: string, permissions: list<array{name: string, ability: string}>}>}>
     */
    private function catalogue(): array
    {
        $catalogue = [];

        foreach (PermissionCatalogue::groupedModules() as $group => $modules) {
            $entries = [];

            foreach ($modules as $key => $definition) {
                $entries[] = [
                    'key' => $key,
                    'label' => $definition['label'],
                    'permissions' => array_map(
                        static fn (string $ability): array => [
                            'name' => "{$key}.{$ability}",
                            'ability' => $ability,
                        ],
                        $definition['abilities'],
                    ),
                ];
            }

            $catalogue[] = ['group' => $group, 'modules' => $entries];
        }

        return $catalogue;
    }
}
