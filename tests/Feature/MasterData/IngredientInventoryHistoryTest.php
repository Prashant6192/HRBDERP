<?php

declare(strict_types=1);

namespace Tests\Feature\MasterData;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Procurement\Models\Vendor;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Issue #8: every batch of an ingredient, with its supplier, QC decision,
 * dates and price, on the ingredient's own page.
 */
class IngredientInventoryHistoryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_ingredient_page_lists_every_batch_with_its_brand_qc_dates_and_price(): void
    {
        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $facility = Facility::factory()->manufacturing()->withStores([WarehouseType::RawMaterial, WarehouseType::Quarantine])->create();
        $store = $facility->stores()->where('type', WarehouseType::RawMaterial->value)->firstOrFail();
        $quarantine = $facility->stores()->where('is_quarantine', true)->firstOrFail();

        $kg = Uom::where('code', 'KG')->sole();
        $manager = User::factory()->create(['name' => 'Mahesh']);
        $manager->assignRole(RoleName::FactoryManager->value);

        $vendor = Vendor::factory()->create(['name' => 'Cladic Chemicals']);
        $betaine = RawMaterial::factory()->create(['code' => 'RM-1001', 'name' => 'Cocamidopropyl Betaine', 'stock_uom_id' => $kg->id]);

        $ledger = app(InventoryLedgerService::class);

        $released = InventoryLot::factory()->forItem($betaine)->create([
            'batch_number' => 'RM260913-001', 'supplier_batch_ref' => 'CB-0459', 'vendor_id' => $vendor->id,
            'qc_status' => LotQcStatus::Approved, 'qc_decided_by' => $manager->id, 'qc_decided_at' => now(),
            'manufactured_at' => '2026-06-05', 'expiry_at' => '2029-09-12', 'unit_cost' => '150', 'initial_quantity' => '50',
            'received_at' => '2026-09-13',
        ]);
        $ledger->receive($betaine, $store, '50', $released, unitCost: '150', userId: $manager->id);

        $waiting = InventoryLot::factory()->forItem($betaine)->pendingQc()->create([
            'batch_number' => 'RM260914-002', 'vendor_id' => $vendor->id, 'unit_cost' => '155', 'initial_quantity' => '20',
            'received_at' => '2026-09-14', 'expiry_at' => '2029-09-13',
        ]);
        $ledger->receive($betaine, $quarantine, '20', $waiting, unitCost: '155', userId: $manager->id);

        $this->actingAs($manager)->get(route('raw-materials.show', $betaine))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('batches.total', 2)
                // 50 x 150 + 20 x 155 = 10,600
                ->where('batches.received_value', '10600.00')
                ->where('batches.rows.0.batch_number', 'RM260914-002')
                ->where('batches.rows.0.qc_status', 'pending')
                ->where('batches.rows.0.qc_status_label', 'Awaiting QC')
                ->where('batches.rows.0.stores.0.quarantine', true)
                ->where('batches.rows.1.batch_number', 'RM260913-001')
                ->where('batches.rows.1.brand', 'Cladic Chemicals')
                ->where('batches.rows.1.brand_kind', 'vendor')
                ->where('batches.rows.1.supplier_batch_ref', 'CB-0459')
                ->where('batches.rows.1.qc_status', 'approved')
                ->where('batches.rows.1.qc_decided_by', 'Mahesh')
                ->where('batches.rows.1.manufactured_at', '2026-06-05')
                ->where('batches.rows.1.expiry_at', '2029-09-12')
                ->where('batches.rows.1.received_quantity', '50')
                ->where('batches.rows.1.on_hand', '50')
                ->where('batches.rows.1.unit_cost', '150.0000')
                ->where('batches.rows.1.value', '7500.00')
                ->where('batches.rows.1.stores.0.quarantine', false));
    }

    #[Test]
    public function someone_who_may_not_see_stock_gets_no_batch_history(): void
    {
        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $kg = Uom::where('code', 'KG')->sole();
        $item = RawMaterial::factory()->create(['stock_uom_id' => $kg->id]);

        $designer = User::factory()->create();
        $designer->assignRole(RoleName::Designer->value);

        $response = $this->actingAs($designer)->get(route('raw-materials.show', $item));

        if ($response->status() === 403) {
            $this->assertTrue(true, 'The role cannot open the page at all, which is stricter still.');

            return;
        }

        $response->assertInertia(fn (AssertableInertia $page) => $page->where('batches', null));
    }
}
