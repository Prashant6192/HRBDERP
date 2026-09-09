<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\InventoryTransaction;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\MasterData\Models\PackagingMaterial;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Procurement\Enums\GoodsReceiptStatus;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Procurement\Models\Vendor;
use App\Domain\Procurement\Services\GoodsReceiptService;
use App\Domain\Quality\Models\QcInspection;
use App\Domain\Quality\Services\QcInspectionService;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Delivery -> batch number -> quarantine -> QC decision -> store.
 */
class GoodsReceiptAndQcTest extends TestCase
{
    use RefreshDatabase;

    private GoodsReceiptService $receipts;

    private QcInspectionService $qc;

    private StockBalanceService $balances;

    private Warehouse $rmStore;

    private Warehouse $quarantine;

    private RawMaterial $material;

    private PackagingMaterial $packaging;

    private Vendor $vendor;

    private User $warehouseManager;

    private User $qcManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->receipts = app(GoodsReceiptService::class);
        $this->qc = app(QcInspectionService::class);
        $this->balances = app(StockBalanceService::class);

        $this->rmStore = Warehouse::factory()->create(['code' => 'WH-RM']);
        $this->quarantine = Warehouse::factory()->quarantine()->create(['code' => 'WH-QA']);

        $this->material = RawMaterial::factory()->create(['code' => 'RM-SLES', 'requires_qc' => true, 'shelf_life_days' => 730]);
        $this->packaging = PackagingMaterial::factory()->create(['code' => 'PM-CAP', 'requires_qc' => false]);
        $this->vendor = Vendor::factory()->create();

        $this->warehouseManager = User::factory()->create();
        $this->warehouseManager->assignRole(RoleName::WarehouseManager->value);

        $this->qcManager = User::factory()->create();
        $this->qcManager->assignRole(RoleName::QcManager->value);
    }

    private function kg(): Uom
    {
        return Uom::where('code', 'KG')->sole();
    }

    private function receipt(array $lines, array $overrides = []): GoodsReceipt
    {
        return $this->receipts->create([
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->rmStore->id,
            'received_at' => now()->toDateString(),
            'invoice_ref' => 'INV-77',
            ...$overrides,
        ], $lines, $this->warehouseManager->id);
    }

    private function materialLine(string $quantity = '25', string $uom = 'KG'): array
    {
        return [
            'item_id' => $this->material->id,
            'quantity' => $quantity,
            'uom_id' => Uom::where('code', $uom)->sole()->id,
            'unit_price' => '118.50',
            'supplier_batch_ref' => 'SUP-001',
            'expiry_at' => now()->addYear()->toDateString(),
        ];
    }

    // ---- Posting -----------------------------------------------------------

    #[Test]
    public function posting_a_receipt_creates_a_lot_and_puts_qc_material_in_quarantine(): void
    {
        $receipt = $this->receipt([$this->materialLine()]);

        $this->assertSame(GoodsReceiptStatus::Draft, $receipt->status);
        $this->assertSame(0, InventoryLot::count(), 'Nothing exists until the receipt is posted.');

        $this->receipts->post($receipt, $this->warehouseManager->id);

        $receipt->refresh();
        $line = $receipt->lines->first();
        $lot = $line->lot;

        $this->assertSame(GoodsReceiptStatus::Received, $receipt->status);
        $this->assertMatchesRegularExpression('/^RM\d{6}-001$/', $lot->batch_number);
        $this->assertSame(LotQcStatus::Pending, $lot->qc_status);
        $this->assertSame('SUP-001', $lot->supplier_batch_ref);
        $this->assertSame($this->vendor->id, $lot->vendor_id);

        // Stock is real, but in quarantine and unavailable to production.
        $this->assertSame('25.000000', (string) $this->balances->onHand($this->material, $this->quarantine));
        $this->assertSame('0', (string) $this->balances->onHand($this->material, $this->rmStore));
        $this->assertSame('0', (string) $this->balances->availableForProduction($this->material));

        $inspection = $line->inspection;
        $this->assertNotNull($inspection);
        $this->assertSame(LotQcStatus::Pending, $inspection->status);
        $this->assertSame($this->rmStore->id, $inspection->destination_warehouse_id);
        $this->assertMatchesRegularExpression('/^QC-\d{4}-\d{5}$/', $inspection->number);
    }

    #[Test]
    public function material_that_needs_no_qc_goes_straight_to_the_store(): void
    {
        $receipt = $this->receipt([[
            'item_id' => $this->packaging->id,
            'quantity' => '5000',
            'uom_id' => Uom::where('code', 'PCS')->sole()->id,
        ]]);

        $this->receipts->post($receipt, $this->warehouseManager->id);

        $lot = $receipt->refresh()->lines->first()->lot;

        $this->assertSame(LotQcStatus::NotRequired, $lot->qc_status);
        $this->assertStringStartsWith('PM', $lot->batch_number);
        $this->assertNull($receipt->lines->first()->qc_inspection_id);
        $this->assertSame('5000.000000', (string) $this->balances->onHand($this->packaging, $this->rmStore));
        $this->assertSame('5000.000000', (string) $this->balances->availableForProduction($this->packaging));
        $this->assertSame(0, QcInspection::count());
    }

    #[Test]
    public function a_quantity_entered_in_grams_is_stocked_in_kilograms(): void
    {
        $receipt = $this->receipt([$this->materialLine('2500', 'G')]);

        $this->receipts->post($receipt, $this->warehouseManager->id);

        $line = $receipt->refresh()->lines->first();

        $this->assertSame('2500.000000', $line->quantity);
        $this->assertSame('2.500000', $line->stock_quantity);
        $this->assertSame('2.500000', (string) $this->balances->onHand($this->material, $this->quarantine));

        // ₹118.50 per gram entered -> ₹118,500 per kilogram stocked.
        $this->assertSame('118500.0000', $line->lot->unit_cost);
    }

    #[Test]
    public function batch_numbers_on_one_receipt_are_sequential(): void
    {
        $receipt = $this->receipt([$this->materialLine('10'), $this->materialLine('20')]);

        $this->receipts->post($receipt, $this->warehouseManager->id);

        $numbers = $receipt->refresh()->lines->pluck('batch_number')->all();

        $this->assertSame(1, (int) substr($numbers[0], -3));
        $this->assertSame(2, (int) substr($numbers[1], -3));
    }

    #[Test]
    public function a_receipt_cannot_be_posted_twice(): void
    {
        $receipt = $this->receipt([$this->materialLine()]);
        $this->receipts->post($receipt, $this->warehouseManager->id);

        $this->expectException(InvalidArgumentException::class);

        $this->receipts->post($receipt->fresh(), $this->warehouseManager->id);
    }

    #[Test]
    public function a_posted_receipt_cannot_be_cancelled(): void
    {
        $receipt = $this->receipt([$this->materialLine()]);
        $this->receipts->post($receipt, $this->warehouseManager->id);

        $this->expectException(InvalidArgumentException::class);

        $this->receipts->cancel($receipt->fresh());
    }

    #[Test]
    public function a_draft_can_be_cancelled_and_creates_no_stock(): void
    {
        $receipt = $this->receipt([$this->materialLine()]);

        $this->receipts->cancel($receipt);

        $this->assertSame(GoodsReceiptStatus::Cancelled, $receipt->fresh()->status);
        $this->assertSame(0, InventoryLot::count());
        $this->assertSame(0, InventoryTransaction::count());
    }

    // ---- QC decisions --------------------------------------------------------

    private function postedInspection(): QcInspection
    {
        $receipt = $this->receipt([$this->materialLine()]);
        $this->receipts->post($receipt, $this->warehouseManager->id);

        return $receipt->refresh()->lines->first()->inspection;
    }

    #[Test]
    public function approving_releases_the_whole_batch_from_quarantine_to_the_store(): void
    {
        $inspection = $this->postedInspection();

        $this->qc->approve($inspection, $this->qcManager->id, 'pH 6.8, appearance OK');

        $lot = $inspection->lot->fresh();

        $this->assertSame(LotQcStatus::Approved, $lot->qc_status);
        $this->assertSame($this->qcManager->id, $lot->qc_decided_by);
        $this->assertSame('0.000000', (string) $this->balances->onHand($this->material, $this->quarantine));
        $this->assertSame('25.000000', (string) $this->balances->onHand($this->material, $this->rmStore));
        $this->assertSame('25.000000', (string) $this->balances->availableForProduction($this->material));

        $release = InventoryTransaction::where('type', InventoryTransactionType::QcRelease->value)->sole();
        $this->assertSame(QcInspection::class, $release->reference_type);
        $this->assertSame($inspection->id, $release->reference_id);
    }

    #[Test]
    public function rejecting_keeps_the_stock_in_quarantine_and_unavailable(): void
    {
        $inspection = $this->postedInspection();

        $this->qc->reject($inspection, $this->qcManager->id, 'Contaminated — visible particles');

        $this->assertSame(LotQcStatus::Rejected, $inspection->lot->fresh()->qc_status);
        $this->assertSame('25.000000', (string) $this->balances->onHand($this->material, $this->quarantine), 'Rejected goods stay on the books until returned.');
        $this->assertSame('0', (string) $this->balances->availableForProduction($this->material));
        $this->assertSame(0, InventoryTransaction::where('type', InventoryTransactionType::QcRelease->value)->count());
    }

    #[Test]
    public function a_decided_inspection_cannot_be_decided_again(): void
    {
        $inspection = $this->postedInspection();
        $this->qc->reject($inspection, $this->qcManager->id, 'Failed');

        $this->expectException(InvalidArgumentException::class);

        $this->qc->approve($inspection->fresh(), $this->qcManager->id);
    }

    #[Test]
    public function a_held_batch_can_still_be_approved_later(): void
    {
        $inspection = $this->postedInspection();

        $this->qc->hold($inspection, $this->qcManager->id, 'Awaiting external lab report');
        $this->assertSame(LotQcStatus::OnHold, $inspection->fresh()->status);
        $this->assertSame(LotQcStatus::OnHold, $inspection->lot->fresh()->qc_status);

        $this->qc->approve($inspection->fresh(), $this->qcManager->id, 'Lab report clear');

        $this->assertSame(LotQcStatus::Approved, $inspection->lot->fresh()->qc_status);
        $this->assertSame('25.000000', (string) $this->balances->onHand($this->material, $this->rmStore));
    }

    #[Test]
    public function approval_cannot_release_into_a_quarantine(): void
    {
        $inspection = $this->postedInspection();

        $this->expectException(InvalidArgumentException::class);

        $this->qc->approve($inspection, $this->qcManager->id, null, null, $this->quarantine);
    }

    // ---- Sticker -------------------------------------------------------------

    #[Test]
    public function an_approved_lot_gets_a_printable_sticker(): void
    {
        $inspection = $this->postedInspection();
        $this->qc->approve($inspection, $this->qcManager->id);

        $response = $this->actingAs($this->qcManager)->get(route('lots.sticker', $inspection->lot_id));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    #[Test]
    public function no_sticker_exists_for_a_batch_qc_has_not_released(): void
    {
        $inspection = $this->postedInspection();

        $this->actingAs($this->qcManager)
            ->get(route('lots.sticker', $inspection->lot_id))
            ->assertStatus(422);
    }

    // ---- Screens and permissions ---------------------------------------------

    #[Test]
    public function a_warehouse_manager_can_book_in_a_delivery_and_post_it_from_the_screen(): void
    {
        $this->actingAs($this->warehouseManager)
            ->post(route('goods-receipts.store'), [
                'vendor_id' => $this->vendor->id,
                'warehouse_id' => $this->rmStore->id,
                'received_at' => now()->toDateString(),
                'invoice_ref' => 'INV-1',
                'post_now' => true,
                'lines' => [$this->materialLine('40')],
            ])
            ->assertRedirect();

        $receipt = GoodsReceipt::sole();

        $this->assertSame(GoodsReceiptStatus::Received, $receipt->status);
        $this->assertSame('40.000000', (string) $this->balances->onHand($this->material, $this->quarantine));
    }

    #[Test]
    public function the_receipt_screen_refuses_a_quarantine_as_destination(): void
    {
        $this->actingAs($this->warehouseManager)
            ->post(route('goods-receipts.store'), [
                'warehouse_id' => $this->quarantine->id,
                'received_at' => now()->toDateString(),
                'lines' => [$this->materialLine()],
            ])
            ->assertSessionHasErrors('warehouse_id');
    }

    #[Test]
    public function an_expiry_before_the_receipt_date_is_refused(): void
    {
        $line = $this->materialLine();
        $line['expiry_at'] = now()->subDay()->toDateString();

        $this->actingAs($this->warehouseManager)
            ->post(route('goods-receipts.store'), [
                'warehouse_id' => $this->rmStore->id,
                'received_at' => now()->toDateString(),
                'lines' => [$line],
            ])
            ->assertSessionHasErrors('lines.0.expiry_at');
    }

    #[Test]
    public function a_designer_cannot_reach_receiving_or_qc(): void
    {
        $designer = User::factory()->create();
        $designer->assignRole(RoleName::Designer->value);

        $this->actingAs($designer)->get(route('goods-receipts.index'))->assertForbidden();
        $this->actingAs($designer)->get(route('qc.index'))->assertForbidden();
        $this->actingAs($designer)->get(route('stock.index'))->assertForbidden();
    }

    #[Test]
    public function a_warehouse_manager_cannot_pass_qc(): void
    {
        $inspection = $this->postedInspection();

        $this->actingAs($this->warehouseManager)
            ->post(route('qc.approve', $inspection), ['remarks' => 'looks fine'])
            ->assertForbidden();

        $this->assertSame(LotQcStatus::Pending, $inspection->fresh()->status);
    }

    #[Test]
    public function a_qc_manager_approves_from_the_screen_and_stock_moves(): void
    {
        $inspection = $this->postedInspection();

        $this->actingAs($this->qcManager)
            ->post(route('qc.approve', $inspection), ['remarks' => 'All parameters within spec'])
            ->assertRedirect(route('qc.show', $inspection));

        $this->assertSame('25.000000', (string) $this->balances->onHand($this->material, $this->rmStore));
    }

    #[Test]
    public function a_rejection_must_say_why(): void
    {
        $inspection = $this->postedInspection();

        $this->actingAs($this->qcManager)
            ->post(route('qc.reject', $inspection), ['remarks' => ''])
            ->assertSessionHasErrors('remarks');

        $this->assertSame(LotQcStatus::Pending, $inspection->fresh()->status);
    }

    #[Test]
    public function the_stock_screen_shows_the_store_with_alert_levels(): void
    {
        $inspection = $this->postedInspection();
        $this->qc->approve($inspection, $this->qcManager->id);

        $this->actingAs($this->warehouseManager)
            ->get(route('stock.index', ['warehouse' => $this->rmStore->id]))
            ->assertOk();
    }
}
