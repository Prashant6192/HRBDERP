<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Domain\Access\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A shop-floor account opens its own screens and nothing else (issue #4).
 */
class ShopFloorRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function a_qc_executive_reaches_only_the_qc_checkpoint(): void
    {
        $qc = $this->userWith(RoleName::QcExecutive);

        $this->actingAs($qc)->get(route('qc.index'))->assertOk();

        foreach (['manufacturing.index', 'plans.index', 'goods-receipts.index', 'stock.index', 'lots.index', 'facilities.index', 'warehouses.index', 'raw-materials.index', 'users.index', 'formulas.index'] as $route) {
            $this->actingAs($qc)->get(route($route))->assertForbidden();
        }

        $this->assertEqualsCanonicalizing(
            ['qc.view', 'qc.create', 'qc.approve', 'qc.reject'],
            $qc->getAllPermissions()->pluck('name')->all(),
        );
    }

    #[Test]
    public function a_packaging_executive_sees_orders_but_cannot_approve_or_plan(): void
    {
        $packer = $this->userWith(RoleName::PackagingExecutive);

        $this->actingAs($packer)->get(route('manufacturing.index'))->assertOk();
        $this->actingAs($packer)->get(route('plans.index'))->assertForbidden();
        $this->actingAs($packer)->get(route('qc.index'))->assertForbidden();
        $this->actingAs($packer)->get(route('stock.index'))->assertForbidden();

        $this->assertFalse($packer->can('production.approve'));
        $this->assertFalse($packer->can('production.create'));
        $this->assertTrue($packer->can('production.consume'));
    }

    #[Test]
    public function a_store_executive_receives_but_does_not_plan_or_make(): void
    {
        $store = $this->userWith(RoleName::StoreExecutive);

        $this->actingAs($store)->get(route('goods-receipts.index'))->assertOk();
        $this->actingAs($store)->get(route('stock.index'))->assertOk();
        $this->actingAs($store)->get(route('plans.index'))->assertForbidden();
        $this->actingAs($store)->get(route('manufacturing.index'))->assertForbidden();
        $this->actingAs($store)->get(route('formulas.index'))->assertForbidden();
    }

    #[Test]
    public function a_production_operator_runs_the_kettle_and_nothing_else(): void
    {
        $operator = $this->userWith(RoleName::ProductionOperator);

        $this->actingAs($operator)->get(route('manufacturing.index'))->assertOk();
        $this->actingAs($operator)->get(route('plans.index'))->assertForbidden();
        $this->actingAs($operator)->get(route('qc.index'))->assertForbidden();
        $this->assertFalse($operator->can('production.approve'));
        $this->assertFalse($operator->can('production.cancel'));
    }

    #[Test]
    public function a_role_new_to_the_code_is_seeded_with_its_defaults_on_sync(): void
    {
        // Simulate a deploy where the role row does not exist yet.
        Role::query()->where('name', RoleName::QcExecutive->value)->delete();

        $this->artisan('erp:sync-permissions')->assertSuccessful();

        $role = Role::query()->where('name', RoleName::QcExecutive->value)->firstOrFail();
        $this->assertEqualsCanonicalizing(RoleName::QcExecutive->permissions(), $role->permissions->pluck('name')->all());
    }

    private function userWith(RoleName $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
