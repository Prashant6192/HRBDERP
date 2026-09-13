<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\MasterData\Models\Product;
use App\Domain\Measurement\Models\Uom;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The A5 label on every outer carton of a finished batch (issue #2).
 */
class CartonLabelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function the_finished_goods_store_records_the_boxing_once_and_prints_a_label_per_box(): void
    {
        $storekeeper = User::factory()->create();
        $storekeeper->assignRole(RoleName::StoreExecutive->value);

        $ml = Uom::where('code', 'ML')->firstOrFail();
        $product = Product::factory()->create(['name' => 'Herbal Shampoo 200 ml', 'net_content' => '200', 'net_content_uom_id' => $ml->id, 'mrp' => '249']);
        $lot = InventoryLot::factory()->forItem($product)->create(['qc_status' => LotQcStatus::Approved, 'batch_number' => 'FG260913-001', 'manufactured_at' => '2026-09-10', 'expiry_at' => '2028-09-09']);

        $this->actingAs($storekeeper)->get(route('lots.cartons', $lot))->assertOk();

        $this->actingAs($storekeeper)
            ->post(route('lots.cartons.store', $lot), ['boxes' => 12, 'units_per_box' => 48, 'gross_weight_kg' => '11.6', 'start_box' => 1, 'remarks' => 'Keep upright'])
            ->assertRedirect();

        $plan = $lot->fresh()->carton_plan;
        $this->assertSame(12, $plan['boxes']);
        $this->assertSame(48, $plan['units_per_box']);
        $this->assertSame('11.6', $plan['gross_weight_kg']);

        $response = $this->actingAs($storekeeper)->get(route('lots.cartons.print', $lot));
        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());

        // A batch QC has not passed prints nothing.
        $held = InventoryLot::factory()->forItem($product)->create(['qc_status' => LotQcStatus::Pending]);
        $this->actingAs($storekeeper)->post(route('lots.cartons.store', $held), ['boxes' => 1, 'units_per_box' => 10])->assertRedirect();
        $this->actingAs($storekeeper)->get(route('lots.cartons.print', $held))->assertStatus(422);

        // Nobody without a store or QC role prints labels.
        $designer = User::factory()->create();
        $designer->assignRole(RoleName::Designer->value);
        $this->actingAs($designer)->get(route('lots.cartons', $lot))->assertForbidden();
    }
}
