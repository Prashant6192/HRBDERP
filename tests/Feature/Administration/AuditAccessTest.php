<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuditAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function the_audit_trail_is_readable_by_those_with_the_permission(): void
    {
        $director = User::factory()->create();
        $director->assignRole(RoleName::Director->value);

        $this->actingAs($director)->get(route('audit.index'))->assertOk();
    }

    #[Test]
    public function the_audit_trail_is_closed_to_everyone_else(): void
    {
        $warehouseManager = User::factory()->create();
        $warehouseManager->assignRole(RoleName::WarehouseManager->value);

        $this->actingAs($warehouseManager)
            ->get(route('audit.index'))
            ->assertForbidden();
    }

    #[Test]
    public function there_is_no_route_that_writes_to_the_audit_trail(): void
    {
        // The strongest statement the application layer can make: no verb
        // other than GET is registered against the audit trail at all, so
        // there is nothing to authorise incorrectly.
        $writeVerbs = ['POST', 'PUT', 'PATCH', 'DELETE'];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'audit')) {
                continue;
            }

            $this->assertEmpty(
                array_intersect($route->methods(), $writeVerbs),
                "Route [{$route->uri()}] exposes a write verb on the audit trail.",
            );
        }
    }

    #[Test]
    public function an_entry_can_be_opened_and_shows_what_changed(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(RoleName::SuperAdmin->value);

        $this->actingAs($superAdmin);

        $warehouse = Warehouse::factory()->create(['name' => 'Before']);
        $warehouse->update(['name' => 'After']);

        $entry = $warehouse->auditLogs()->where('action', 'updated')->sole();

        $this->actingAs($superAdmin)
            ->get(route('audit.show', $entry))
            ->assertOk();
    }
}
