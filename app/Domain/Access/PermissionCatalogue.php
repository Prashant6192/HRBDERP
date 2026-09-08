<?php

declare(strict_types=1);

namespace App\Domain\Access;

use InvalidArgumentException;

/**
 * The single source of truth for every permission the ERP recognises.
 *
 * Permissions are named "<module>.<ability>" — for example "inventory.receive"
 * or "formula.approve". Nothing outside this class may invent a permission
 * name: the seeder builds the permission table from here, roles are defined in
 * terms of these names, and the test suite asserts that every permission
 * referenced in a policy or route actually exists.
 *
 * Adding a module or ability is therefore a one-line change here, followed by
 * `php artisan erp:sync-permissions`.
 */
final class PermissionCatalogue
{
    /**
     * Abilities shared by most master-data modules.
     *
     * @var list<string>
     */
    private const CRUD = ['view', 'create', 'edit', 'delete', 'export'];

    /**
     * Every module, its human label, the navigation group it belongs to, and
     * the abilities it supports.
     *
     * @var array<string, array{label: string, group: string, abilities: list<string>}>
     */
    public const MODULES = [

        // ---- Administration ------------------------------------------------
        'user' => [
            'label' => 'Users',
            'group' => 'Administration',
            'abilities' => ['view', 'create', 'edit', 'delete', 'export', 'impersonate'],
        ],
        'role' => [
            'label' => 'Roles & Permissions',
            'group' => 'Administration',
            'abilities' => ['view', 'create', 'edit', 'delete'],
        ],
        'department' => [
            'label' => 'Departments',
            'group' => 'Administration',
            'abilities' => ['view', 'create', 'edit', 'delete'],
        ],
        'audit' => [
            'label' => 'Audit Log',
            'group' => 'Administration',
            'abilities' => ['view', 'export'],
        ],
        'setting' => [
            'label' => 'Settings',
            'group' => 'Administration',
            'abilities' => ['view', 'edit'],
        ],

        // ---- Master data ---------------------------------------------------
        'warehouse' => [
            'label' => 'Warehouses',
            'group' => 'Master Data',
            'abilities' => self::CRUD,
        ],
        'product' => [
            'label' => 'Product Master',
            'group' => 'Master Data',
            'abilities' => [...self::CRUD, 'import'],
        ],
        'raw_material' => [
            'label' => 'Raw Materials',
            'group' => 'Master Data',
            'abilities' => [...self::CRUD, 'import'],
        ],
        'packaging_material' => [
            'label' => 'Packaging Materials',
            'group' => 'Master Data',
            'abilities' => [...self::CRUD, 'import'],
        ],
        'vendor' => [
            'label' => 'Vendors',
            'group' => 'Master Data',
            'abilities' => self::CRUD,
        ],
        'uom' => [
            'label' => 'Units of Measure',
            'group' => 'Master Data',
            'abilities' => ['view', 'create', 'edit', 'delete'],
        ],

        // ---- Operations ----------------------------------------------------
        'inventory' => [
            'label' => 'Inventory',
            'group' => 'Operations',
            'abilities' => ['view', 'receive', 'adjust', 'transfer', 'reserve', 'consume', 'export'],
        ],
        'formula' => [
            'label' => 'Formulations',
            'group' => 'Operations',
            'abilities' => ['view', 'create', 'edit', 'approve', 'archive', 'export'],
        ],
        'production' => [
            'label' => 'Production',
            'group' => 'Operations',
            'abilities' => ['view', 'create', 'edit', 'approve', 'consume', 'cancel', 'export'],
        ],
        'purchase' => [
            'label' => 'Procurement',
            'group' => 'Operations',
            'abilities' => ['view', 'create', 'edit', 'approve', 'receive', 'export'],
        ],
        'qc' => [
            'label' => 'Quality Control',
            'group' => 'Operations',
            'abilities' => ['view', 'create', 'approve', 'reject', 'export'],
        ],

        // ---- Commercial ----------------------------------------------------
        'sales' => [
            'label' => 'Sales',
            'group' => 'Commercial',
            'abilities' => self::CRUD,
        ],
        'marketplace' => [
            'label' => 'Marketplace',
            'group' => 'Commercial',
            'abilities' => ['view', 'import', 'reconcile', 'export'],
        ],
        'costing' => [
            'label' => 'Costing',
            'group' => 'Commercial',
            'abilities' => ['view', 'edit', 'export'],
        ],
        'report' => [
            'label' => 'Reports',
            'group' => 'Commercial',
            'abilities' => ['view', 'export'],
        ],

        // ---- Workflow ------------------------------------------------------
        'approval' => [
            'label' => 'Approvals',
            'group' => 'Workflow',
            'abilities' => ['view', 'act'],
        ],
    ];

    /**
     * Every permission name, flattened.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $permissions = [];

        foreach (self::MODULES as $module => $definition) {
            foreach ($definition['abilities'] as $ability) {
                $permissions[] = "{$module}.{$ability}";
            }
        }

        return $permissions;
    }

    /**
     * Every permission belonging to a single module.
     *
     * @return list<string>
     */
    public static function forModule(string $module): array
    {
        if (! isset(self::MODULES[$module])) {
            throw new InvalidArgumentException("Unknown ERP module [{$module}].");
        }

        return array_map(
            static fn (string $ability): string => "{$module}.{$ability}",
            self::MODULES[$module]['abilities'],
        );
    }

    /**
     * Expand a list that may contain "module.*" wildcards into concrete names.
     *
     * Used by the role definitions so that a role granted "inventory.*"
     * automatically picks up any ability added to that module later.
     *
     * @param  list<string>  $patterns
     * @return list<string>
     */
    public static function expand(array $patterns): array
    {
        $expanded = [];

        foreach ($patterns as $pattern) {
            if ($pattern === '*') {
                return self::all();
            }

            if (str_ends_with($pattern, '.*')) {
                $module = substr($pattern, 0, -2);
                $expanded = [...$expanded, ...self::forModule($module)];

                continue;
            }

            self::assertExists($pattern);
            $expanded[] = $pattern;
        }

        return array_values(array_unique($expanded));
    }

    /**
     * Guard against typos in policies, routes and role definitions.
     */
    public static function assertExists(string $permission): void
    {
        if (! in_array($permission, self::all(), strict: true)) {
            throw new InvalidArgumentException("Unknown ERP permission [{$permission}].");
        }
    }

    /**
     * Modules keyed by their navigation group, for rendering the sidebar and
     * the role editor.
     *
     * @return array<string, array<string, array{label: string, group: string, abilities: list<string>}>>
     */
    public static function groupedModules(): array
    {
        $grouped = [];

        foreach (self::MODULES as $module => $definition) {
            $grouped[$definition['group']][$module] = $definition;
        }

        return $grouped;
    }
}
