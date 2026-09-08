<?php

declare(strict_types=1);

namespace Tests\Feature\MasterData;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WarehouseManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $warehouseManager;

    private User $designer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->warehouseManager = User::factory()->create();
        $this->warehouseManager->assignRole(RoleName::WarehouseManager->value);

        $this->designer = User::factory()->create();
        $this->designer->assignRole(RoleName::Designer->value);
    }

    #[Test]
    public function a_warehouse_manager_sees_the_warehouse_list(): void
    {
        Warehouse::factory()->count(3)->create();

        $this->actingAs($this->warehouseManager)
            ->get(route('warehouses.index'))
            ->assertOk();
    }

    #[Test]
    public function a_role_without_the_permission_is_refused(): void
    {
        $this->actingAs($this->designer)
            ->get(route('warehouses.index'))
            ->assertForbidden();
    }

    #[Test]
    public function a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('warehouses.index'))->assertRedirect(route('login'));
    }

    #[Test]
    public function a_warehouse_can_be_created(): void
    {
        $response = $this->actingAs($this->warehouseManager)
            ->post(route('warehouses.store'), [
                'code' => 'wh-new',
                'name' => 'New Raw Material Store',
                'type' => WarehouseType::RawMaterial->value,
                'city' => 'Pune',
                'is_active' => true,
                'is_quarantine' => false,
            ]);

        $response->assertRedirect(route('warehouses.index'));

        // The code is upper-cased by the form request rather than trusted as
        // typed, so that two warehouses cannot differ only by case.
        $this->assertDatabaseHas('warehouses', [
            'code' => 'WH-NEW',
            'name' => 'New Raw Material Store',
        ]);
    }

    #[Test]
    public function creating_a_warehouse_is_recorded_in_the_audit_trail(): void
    {
        $this->actingAs($this->warehouseManager)
            ->post(route('warehouses.store'), [
                'code' => 'WH-AUD',
                'name' => 'Audited Store',
                'type' => WarehouseType::General->value,
                'is_active' => true,
            ]);

        $warehouse = Warehouse::where('code', 'WH-AUD')->sole();

        $entry = AuditLog::forEntity($warehouse)->action(AuditAction::Created)->sole();

        $this->assertSame($this->warehouseManager->id, $entry->user_id);
        $this->assertSame('WH-AUD — Audited Store', $entry->auditable_label);
    }

    #[Test]
    public function a_duplicate_code_is_rejected(): void
    {
        Warehouse::factory()->create(['code' => 'WH-DUP']);

        $this->actingAs($this->warehouseManager)
            ->post(route('warehouses.store'), [
                'code' => 'WH-DUP',
                'name' => 'Another Store',
                'type' => WarehouseType::General->value,
            ])
            ->assertSessionHasErrors('code');

        $this->assertSame(1, Warehouse::where('code', 'WH-DUP')->count());
    }

    #[Test]
    public function required_fields_are_enforced(): void
    {
        $this->actingAs($this->warehouseManager)
            ->post(route('warehouses.store'), [])
            ->assertSessionHasErrors(['code', 'name', 'type']);
    }

    #[Test]
    public function an_invalid_type_is_rejected(): void
    {
        $this->actingAs($this->warehouseManager)
            ->post(route('warehouses.store'), [
                'code' => 'WH-BAD',
                'name' => 'Bad Type',
                'type' => 'teleporter',
            ])
            ->assertSessionHasErrors('type');
    }

    #[Test]
    public function a_warehouse_can_be_updated(): void
    {
        $warehouse = Warehouse::factory()->create(['name' => 'Old Name']);

        $this->actingAs($this->warehouseManager)
            ->put(route('warehouses.update', $warehouse), [
                'code' => $warehouse->code,
                'name' => 'Renamed Store',
                'type' => $warehouse->type->value,
                'is_active' => true,
            ])
            ->assertRedirect(route('warehouses.show', $warehouse));

        $this->assertSame('Renamed Store', $warehouse->refresh()->name);
    }

    #[Test]
    public function a_warehouse_keeps_its_own_code_when_updated(): void
    {
        // The unique rule has to ignore the record being edited, or saving a
        // warehouse without touching its code would fail.
        $warehouse = Warehouse::factory()->create(['code' => 'WH-KEEP']);

        $this->actingAs($this->warehouseManager)
            ->put(route('warehouses.update', $warehouse), [
                'code' => 'WH-KEEP',
                'name' => 'Still Here',
                'type' => $warehouse->type->value,
            ])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function deleting_a_warehouse_only_soft_deletes_it(): void
    {
        $warehouse = Warehouse::factory()->create();

        $this->actingAs($this->warehouseManager)
            ->delete(route('warehouses.destroy', $warehouse))
            ->assertRedirect(route('warehouses.index'));

        // Its stock history still refers to it, so the row has to survive.
        $this->assertSoftDeleted('warehouses', ['id' => $warehouse->id]);
    }

    #[Test]
    public function a_user_without_edit_permission_cannot_update(): void
    {
        $warehouse = Warehouse::factory()->create();

        $viewer = User::factory()->create();
        $viewer->assignRole(RoleName::Viewer->value);

        $this->actingAs($viewer)
            ->put(route('warehouses.update', $warehouse), [
                'code' => $warehouse->code,
                'name' => 'Should Not Save',
                'type' => $warehouse->type->value,
            ])
            ->assertForbidden();

        $this->assertNotSame('Should Not Save', $warehouse->refresh()->name);
    }

    #[Test]
    public function the_list_can_be_searched_and_filtered(): void
    {
        Warehouse::factory()->create(['code' => 'WH-AAA', 'name' => 'Alpha Store']);
        Warehouse::factory()->create(['code' => 'WH-BBB', 'name' => 'Beta Store']);

        $this->actingAs($this->warehouseManager)
            ->get(route('warehouses.index', ['search' => 'Alpha']))
            ->assertOk();

        $this->actingAs($this->warehouseManager)
            ->get(route('warehouses.index', ['status' => 'active']))
            ->assertOk();
    }

    #[Test]
    public function an_unknown_sort_column_is_ignored_rather_than_run(): void
    {
        Warehouse::factory()->count(2)->create();

        // If the column reached the database this would be a SQL error.
        $this->actingAs($this->warehouseManager)
            ->get(route('warehouses.index', [
                'sort' => 'id; drop table warehouses',
                'direction' => 'desc',
            ]))
            ->assertOk();

        $this->assertSame(2, Warehouse::count());
    }
}
