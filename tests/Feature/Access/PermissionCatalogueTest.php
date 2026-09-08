<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Access\PermissionCatalogue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The catalogue is the contract between roles, policies and the seeder, so a
 * typo in it is a silent authorisation hole rather than an error.
 */
class PermissionCatalogueTest extends TestCase
{
    #[Test]
    public function every_permission_is_named_module_dot_ability(): void
    {
        foreach (PermissionCatalogue::all() as $permission) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z_]*\.[a-z][a-z_]*$/',
                $permission,
                "Permission [{$permission}] does not follow the module.ability convention.",
            );
        }
    }

    #[Test]
    public function permission_names_are_unique(): void
    {
        $all = PermissionCatalogue::all();

        $this->assertSame(
            count($all),
            count(array_unique($all)),
            'The catalogue contains duplicate permission names.',
        );
    }

    #[Test]
    public function every_module_grants_at_least_a_view_ability(): void
    {
        foreach (PermissionCatalogue::MODULES as $module => $definition) {
            $this->assertContains(
                'view',
                $definition['abilities'],
                "Module [{$module}] has no view ability, so nobody can read it.",
            );
        }
    }

    #[Test]
    public function a_wildcard_expands_to_every_ability_of_its_module(): void
    {
        $expanded = PermissionCatalogue::expand(['warehouse.*']);

        $this->assertSame(PermissionCatalogue::forModule('warehouse'), $expanded);
    }

    #[Test]
    public function the_global_wildcard_expands_to_everything(): void
    {
        $this->assertSame(
            PermissionCatalogue::all(),
            PermissionCatalogue::expand(['*']),
        );
    }

    #[Test]
    public function expanding_an_unknown_permission_fails_loudly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PermissionCatalogue::expand(['inventory.teleport']);
    }

    #[Test]
    public function expanding_an_unknown_module_fails_loudly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PermissionCatalogue::expand(['warehousing.*']);
    }

    #[Test]
    public function every_role_resolves_to_permissions_that_exist(): void
    {
        foreach (RoleName::all() as $role) {
            // permissions() runs the expansion, which validates every name.
            $permissions = $role->permissions();

            $this->assertNotEmpty(
                $permissions,
                "Role [{$role->value}] grants no permissions at all.",
            );

            foreach ($permissions as $permission) {
                $this->assertContains($permission, PermissionCatalogue::all());
            }
        }
    }

    #[Test]
    public function no_role_other_than_the_top_two_is_granted_everything(): void
    {
        $everything = count(PermissionCatalogue::all());

        foreach (RoleName::all() as $role) {
            if (in_array($role, [RoleName::SuperAdmin, RoleName::Owner], strict: true)) {
                continue;
            }

            $this->assertLessThan(
                $everything,
                count($role->permissions()),
                "Role [{$role->value}] holds every permission, which defeats the point of having roles.",
            );
        }
    }

    #[Test]
    public function roles_that_must_not_read_formulations_do_not(): void
    {
        // Formulations are the company's trade secret. These roles have no
        // business reason to see them, and this test is here to make removing
        // that restriction a deliberate act.
        $excluded = [
            RoleName::Designer,
            RoleName::MarketingManager,
            RoleName::EcommerceManager,
            RoleName::SalesManager,
            RoleName::BrandManager,
            RoleName::WarehouseManager,
            RoleName::AccountsManager,
            RoleName::Viewer,
        ];

        foreach ($excluded as $role) {
            $this->assertNotContains(
                'formula.view',
                $role->permissions(),
                "Role [{$role->value}] should not be able to read formulations.",
            );
        }
    }
}
