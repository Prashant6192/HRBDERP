<?php

declare(strict_types=1);

namespace Tests\Feature\Warehousing;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Formulation\Services\FormulaService;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Exceptions\OpeningStockException;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\InventoryTransaction;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Inventory\Services\OpeningStockService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\Inventory\Services\StockTransferService;
use App\Domain\Manufacturing\Exceptions\ManufacturingException;
use App\Domain\Manufacturing\Services\ManufacturingOrderService;
use App\Domain\MasterData\Models\PackagingMaterial;
use App\Domain\MasterData\Models\Product;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Planning\Exceptions\PlanningException;
use App\Domain\Planning\Services\ProductionPlanService;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Exceptions\FacilityAccessDeniedException;
use App\Domain\Warehousing\Exceptions\FacilityException;
use App\Domain\Warehousing\Models\EmployeeAssignment;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\StoreCategory;
use App\Domain\Warehousing\Models\Warehouse;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Domain\Warehousing\Services\FacilityService;
use App\Domain\Warehousing\Services\StoreService;
use App\Domain\Warehousing\Services\WarehouseResolver;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The business rules the facility change request asked to be proven:
 * production only where manufacturing is enabled, availability counted at
 * the planning facility alone, transfers that reach the destination only
 * on receipt, people confined to their facility, and stores that are
 * never deleted once used.
 */
class FacilityOperationsTest extends TestCase
{
    use RefreshDatabase;

    private Facility $rudrapur;

    private Facility $delhi;

    private Warehouse $rudrapurRm;

    private Warehouse $rudrapurPm;

    private Warehouse $rudrapurFg;

    private Warehouse $rudrapurQuarantine;

    private Warehouse $delhiFg;

    private User $factoryManager;

    private User $delhiStorekeeper;

    private User $owner;

    private RawMaterial $surfactant;

    private RawMaterial $water;

    private PackagingMaterial $bottle;

    private Product $product;

    private Formula $formula;

    private Uom $kg;

    private InventoryLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->ledger = app(InventoryLedgerService::class);

        $this->owner = User::factory()->create();
        $this->owner->assignRole(RoleName::Owner->value);
        $this->factoryManager = User::factory()->create();
        $this->factoryManager->assignRole(RoleName::FactoryManager->value);
        $this->delhiStorekeeper = User::factory()->create();
        $this->delhiStorekeeper->assignRole(RoleName::WarehouseManager->value);

        $this->rudrapur = Facility::factory()->manufacturing()->create(['code' => 'FAC-RDP-001', 'name' => 'Rudrapur Manufacturing Facility', 'city' => 'Rudrapur']);
        $this->rudrapurRm = Warehouse::factory()->atFacility($this->rudrapur)->ofType(WarehouseType::RawMaterial)->create(['code' => 'RDP-RM']);
        $this->rudrapurPm = Warehouse::factory()->atFacility($this->rudrapur)->ofType(WarehouseType::Packaging)->create(['code' => 'RDP-PM']);
        $this->rudrapurFg = Warehouse::factory()->atFacility($this->rudrapur)->ofType(WarehouseType::FinishedGoods)->create(['code' => 'RDP-FG']);
        $this->rudrapurQuarantine = Warehouse::factory()->atFacility($this->rudrapur)->quarantine()->create(['code' => 'RDP-QUAR']);

        $this->delhi = Facility::factory()->create(['code' => 'FAC-DEL-001', 'name' => 'Delhi Warehouse', 'city' => 'Delhi', 'can_manufacture' => false]);
        $this->delhiFg = Warehouse::factory()->atFacility($this->delhi)->ofType(WarehouseType::FinishedGoods)->create(['code' => 'DEL-FG']);

        EmployeeAssignment::factory()->create(['user_id' => $this->factoryManager->id, 'facility_id' => $this->rudrapur->id]);
        EmployeeAssignment::factory()->create(['user_id' => $this->delhiStorekeeper->id, 'facility_id' => $this->delhi->id]);

        $this->kg = Uom::where('code', 'KG')->firstOrFail();
        $ml = Uom::where('code', 'ML')->firstOrFail();
        $pcs = Uom::where('code', 'PCS')->firstOrFail();

        $this->surfactant = RawMaterial::factory()->create(['name' => 'Test Surfactant', 'stock_uom_id' => $this->kg->id, 'reorder_level' => null, 'minimum_stock' => null]);
        $this->water = RawMaterial::factory()->create(['name' => 'Purified Water', 'stock_uom_id' => $this->kg->id, 'density_g_per_ml' => '1', 'reorder_level' => null, 'minimum_stock' => null]);
        $this->bottle = PackagingMaterial::factory()->create(['name' => 'Bottle 100 ml', 'stock_uom_id' => $pcs->id, 'reorder_level' => null, 'minimum_stock' => null]);

        $this->product = Product::factory()->create([
            'name' => 'Test Face Wash 100 ml', 'net_content' => '100', 'net_content_uom_id' => $ml->id, 'stock_uom_id' => $pcs->id, 'requires_qc' => false,
        ]);
        $this->product->packagingLines()->create(['packaging_material_id' => $this->bottle->id, 'quantity_per_unit' => '1']);

        $formulas = app(FormulaService::class);
        $this->formula = $formulas->create([
            'name' => 'Test Face Wash', 'product_id' => $this->product->id, 'batch_uom_id' => Uom::where('code', 'G')->value('id'),
        ], [
            ['item_id' => $this->surfactant->id, 'percentage' => '50'],
            ['item_id' => $this->water->id, 'is_qs' => true],
        ], $this->factoryManager->id);
        $formulas->activate($this->formula->versions()->first(), $this->factoryManager->id);
        $this->formula->refresh();
    }

    // ---- TEST 1 & 2: production only where manufacturing is enabled --------

    #[Test]
    public function a_manufacturing_order_is_raised_at_the_manufacturing_facility(): void
    {
        $this->stock($this->rudrapurRm, $this->surfactant, '100');
        $this->stock($this->rudrapurRm, $this->water, '500');
        $this->stock($this->rudrapurPm, $this->bottle, '5000');

        $plan = app(ProductionPlanService::class)->create([
            'formula_id' => $this->formula->id, 'quantity' => '100', 'uom_id' => $this->kg->id, 'facility_id' => $this->rudrapur->id,
        ], $this->factoryManager->id);

        $order = app(ManufacturingOrderService::class)->createFromPlan($plan, $this->factoryManager->id);
        $order = app(ManufacturingOrderService::class)->approve($order, $this->factoryManager->id);

        $this->assertSame($this->rudrapur->id, $plan->facility_id);
        $this->assertSame($this->rudrapur->id, $order->facility_id);
        $this->assertTrue($order->reservations()->exists());
    }

    #[Test]
    public function a_plan_defaults_to_the_facility_that_can_manufacture(): void
    {
        $plan = app(ProductionPlanService::class)->create([
            'formula_id' => $this->formula->id, 'quantity' => '10', 'uom_id' => $this->kg->id,
        ], $this->factoryManager->id);

        $this->assertSame($this->rudrapur->id, $plan->facility_id);
    }

    #[Test]
    public function a_facility_without_manufacturing_cannot_plan_or_make_a_batch(): void
    {
        try {
            app(ProductionPlanService::class)->create([
                'formula_id' => $this->formula->id, 'quantity' => '10', 'uom_id' => $this->kg->id, 'facility_id' => $this->delhi->id,
            ], $this->factoryManager->id);
            $this->fail('Planning at a non-manufacturing facility should be refused.');
        } catch (PlanningException $e) {
            $this->assertStringContainsString('manufacturing is not enabled', $e->getMessage());
        }

        // Even a plan that somehow names Delhi cannot become an order.
        $plan = app(ProductionPlanService::class)->create([
            'formula_id' => $this->formula->id, 'quantity' => '10', 'uom_id' => $this->kg->id,
        ], $this->factoryManager->id);
        $plan->forceFill(['facility_id' => $this->delhi->id])->save();

        try {
            app(ManufacturingOrderService::class)->createFromPlan($plan, $this->factoryManager->id);
            $this->fail('An order for a non-manufacturing facility should be refused.');
        } catch (ManufacturingException $e) {
            $this->assertStringContainsString('manufacturing is not enabled', $e->getMessage());
        }

        $this->assertDatabaseCount('manufacturing_orders', 0);
    }

    // ---- TEST 3: availability is counted at the planning facility only ------

    #[Test]
    public function the_requirement_check_counts_only_the_manufacturing_facility_and_points_at_the_rest(): void
    {
        $delhiRm = Warehouse::factory()->atFacility($this->delhi)->ofType(WarehouseType::RawMaterial)->create(['code' => 'DEL-RM']);

        $this->stock($this->rudrapurRm, $this->surfactant, '20');
        $this->stock($delhiRm, $this->surfactant, '100');

        // 100 kg batch at 50 % → 50 kg of surfactant needed.
        $plan = app(ProductionPlanService::class)->create([
            'formula_id' => $this->formula->id, 'quantity' => '100', 'uom_id' => $this->kg->id, 'facility_id' => $this->rudrapur->id,
        ], $this->factoryManager->id);

        $line = $plan->lines->firstWhere('item_id', $this->surfactant->id);

        $this->assertSame('50.000000', $line->required_quantity);
        $this->assertSame('20.000000', $line->available_quantity);
        $this->assertSame('30.000000', $line->shortage_quantity);
        $this->assertCount(1, $line->available_elsewhere);
        $this->assertSame($this->delhi->id, $line->available_elsewhere[0]['facility_id']);
        $this->assertSame('Delhi Warehouse', $line->available_elsewhere[0]['facility']);
        $this->assertSame('100.000000', $line->available_elsewhere[0]['quantity']);
    }

    // ---- TEST 4: the transfer lifecycle -------------------------------------

    #[Test]
    public function a_transfer_reaches_the_destination_only_when_it_is_received(): void
    {
        $lot = InventoryLot::factory()->forItem($this->product)->create(['qc_status' => LotQcStatus::Approved, 'batch_number' => 'FG-2609-0001']);
        $this->ledger->receive($this->product, $this->rudrapurFg, '500', $lot, userId: $this->factoryManager->id);

        $transfers = app(StockTransferService::class);
        $balances = app(StockBalanceService::class);
        $transit = app(WarehouseResolver::class)->inTransit();

        $transfer = $transfers->create([
            'source_warehouse_id' => $this->rudrapurFg->id, 'destination_warehouse_id' => $this->delhiFg->id, 'reason' => 'Delhi launch stock',
        ], [
            ['item_id' => $this->product->id, 'quantity' => '200'],
        ], $this->factoryManager->id);

        $this->assertSame(StockTransferStatus::Draft, $transfer->status);
        $this->assertStringStartsWith('TRF-', $transfer->number);

        $transfer = $transfers->request($transfer, $this->factoryManager->id);
        $transfer = $transfers->approve($transfer, $this->owner->id);

        $this->assertSame(StockTransferStatus::Approved, $transfer->status);
        $this->assertSame('200.000000', $balances->reserved($this->product, $this->rudrapurFg)->__toString());
        $this->assertSame('300.000000', $balances->available($this->product, $this->rudrapurFg)->__toString());
        $this->assertSame('0', $balances->onHand($this->product, $this->delhiFg)->__toString());
        $this->assertSame($lot->id, $transfer->lines->first()->lot_id, 'The line names the batch that was picked.');

        $transfer = $transfers->dispatch($transfer, $this->factoryManager->id, 'UK-06-1234');

        $this->assertSame(StockTransferStatus::Dispatched, $transfer->status);
        $this->assertSame('300.000000', $balances->onHand($this->product, $this->rudrapurFg)->__toString());
        $this->assertSame('0.000000', $balances->reserved($this->product, $this->rudrapurFg)->__toString());
        $this->assertSame('200.000000', $balances->onHand($this->product, $transit)->__toString());
        $this->assertSame('0', $balances->onHand($this->product, $this->delhiFg)->__toString(), 'Nothing shows at Delhi while the lorry is on the road.');

        $transfer = $transfers->markInTransit($transfer);
        $this->assertSame(StockTransferStatus::InTransit, $transfer->status);

        $transfer = $transfers->receive($transfer, $this->delhiStorekeeper->id, [
            $transfer->lines->first()->id => ['quantity' => '200'],
        ]);

        $this->assertSame(StockTransferStatus::Received, $transfer->status);
        $this->assertSame('0.000000', $balances->onHand($this->product, $transit)->__toString());
        $this->assertSame('200.000000', $balances->onHand($this->product, $this->delhiFg)->__toString());

        // The batch is the same batch at both ends.
        $this->assertTrue(StockBalance::query()->where('warehouse_id', $this->delhiFg->id)->where('lot_id', $lot->id)->where('on_hand', '200')->exists());
        $this->assertSame(2, InventoryTransaction::query()->where('reference_type', $transfer->getMorphClass())->where('reference_id', $transfer->id)->count());
    }

    #[Test]
    public function a_short_delivery_can_be_written_off_and_the_transfer_says_so(): void
    {
        $lot = InventoryLot::factory()->forItem($this->product)->create(['qc_status' => LotQcStatus::Approved]);
        $this->ledger->receive($this->product, $this->rudrapurFg, '100', $lot, userId: $this->factoryManager->id);

        $transfers = app(StockTransferService::class);
        $transfer = $transfers->create(['source_warehouse_id' => $this->rudrapurFg->id, 'destination_warehouse_id' => $this->delhiFg->id], [['item_id' => $this->product->id, 'quantity' => '100']], $this->factoryManager->id);
        $transfer = $transfers->approve($transfer, $this->owner->id);
        $transfer = $transfers->dispatch($transfer, $this->factoryManager->id);
        $transfer = $transfers->receive($transfer, $this->delhiStorekeeper->id, [$transfer->lines->first()->id => ['quantity' => '95', 'written_off' => '5', 'notes' => 'Five bottles crushed']]);

        $this->assertSame(StockTransferStatus::Discrepancy, $transfer->status);
        $this->assertSame('95.000000', app(StockBalanceService::class)->onHand($this->product, $this->delhiFg)->__toString());
        $this->assertSame('0.000000', app(StockBalanceService::class)->onHand($this->product, app(WarehouseResolver::class)->inTransit())->__toString());
    }

    #[Test]
    public function approval_refuses_a_transfer_the_source_cannot_cover(): void
    {
        $lot = InventoryLot::factory()->forItem($this->product)->create(['qc_status' => LotQcStatus::Approved]);
        $this->ledger->receive($this->product, $this->rudrapurFg, '10', $lot, userId: $this->factoryManager->id);

        $transfers = app(StockTransferService::class);
        $transfer = $transfers->create(['source_warehouse_id' => $this->rudrapurFg->id, 'destination_warehouse_id' => $this->delhiFg->id], [['item_id' => $this->product->id, 'quantity' => '50']], $this->factoryManager->id);

        $this->expectExceptionMessage('Quantity not available');
        $transfers->approve($transfer, $this->owner->id);
    }

    // ---- TEST 5: people are confined to their facility -----------------------

    #[Test]
    public function an_employee_assigned_only_to_delhi_cannot_touch_rudrapur_stock(): void
    {
        $access = app(FacilityAccess::class);

        $this->assertTrue($access->canWorkIn($this->delhiStorekeeper, $this->delhiFg));
        $this->assertFalse($access->canWorkIn($this->delhiStorekeeper, $this->rudrapurRm));
        $this->assertFalse($access->canWorkAt($this->delhiStorekeeper, $this->rudrapur));
        $this->assertTrue($access->canWorkAt($this->owner, $this->rudrapur), 'Owners work company-wide.');

        $this->expectException(FacilityAccessDeniedException::class);
        $access->assertCanWorkIn($this->delhiStorekeeper, $this->rudrapurRm);
    }

    #[Test]
    public function a_store_level_assignment_is_narrower_than_a_facility_one(): void
    {
        $picker = User::factory()->create();
        $picker->assignRole(RoleName::WarehouseManager->value);
        EmployeeAssignment::factory()->create(['user_id' => $picker->id, 'facility_id' => $this->rudrapur->id, 'store_id' => $this->rudrapurFg->id]);

        $access = app(FacilityAccess::class);

        $this->assertTrue($access->canWorkIn($picker, $this->rudrapurFg));
        $this->assertFalse($access->canWorkIn($picker, $this->rudrapurRm));
        $this->assertTrue($access->canWorkAt($picker, $this->rudrapur));
    }

    // ---- TEST 6: editing a facility leaves its history alone -----------------

    #[Test]
    public function adding_a_samples_store_to_rudrapur_changes_nothing_that_already_happened(): void
    {
        $this->stock($this->rudrapurRm, $this->surfactant, '40');
        $before = InventoryTransaction::query()->with('lines')->get()->toArray();
        $balancesBefore = StockBalance::query()->orderBy('id')->get(['item_id', 'warehouse_id', 'lot_id', 'on_hand', 'reserved'])->toArray();
        $storesBefore = $this->rudrapur->stores()->pluck('code')->all();

        $facilities = app(FacilityService::class);
        $facilities->update($this->rudrapur, ['name' => 'Rudrapur Manufacturing Facility', 'phone' => '05944-250100', 'can_return' => true], $this->owner->id);
        $added = $facilities->addStores($this->rudrapur, [
            ['store_category_id' => StoreCategory::query()->where('code', 'SMPL')->value('id'), 'name' => 'Samples Store', 'default_location' => 'Rack S-1'],
        ], $this->owner->id);

        $this->assertCount(1, $added);
        $this->assertSame('RDP-SMPL', $added[0]->code);
        $this->assertSame(WarehouseType::Samples, $added[0]->type);
        $this->assertSame($this->rudrapur->id, $added[0]->facility_id);
        $this->assertSame('RACK-S-1', $added[0]->locations()->first()->code);

        $this->assertEqualsCanonicalizing([...$storesBefore, 'RDP-SMPL'], $this->rudrapur->stores()->pluck('code')->all());
        $this->assertSame($before, InventoryTransaction::query()->with('lines')->get()->toArray());
        $this->assertSame($balancesBefore, StockBalance::query()->orderBy('id')->get(['item_id', 'warehouse_id', 'lot_id', 'on_hand', 'reserved'])->toArray());
        $this->assertSame('05944-250100', $this->rudrapur->fresh()->phone);
    }

    // ---- TEST 7: a used store is deactivated, never deleted ------------------

    #[Test]
    public function a_store_with_ledger_history_cannot_be_deleted_only_deactivated(): void
    {
        $this->stock($this->rudrapurRm, $this->surfactant, '5');
        $stores = app(StoreService::class);

        try {
            $stores->delete($this->rudrapurRm);
            $this->fail('A store with history must not be deleted.');
        } catch (FacilityException $e) {
            $this->assertSame('This store cannot be deleted because operational history exists. Deactivate it instead.', $e->getMessage());
        }

        $this->assertNotSoftDeleted('warehouses', ['id' => $this->rudrapurRm->id]);

        // Not while it still holds stock, either.
        try {
            $stores->deactivate($this->rudrapurRm, $this->owner->id);
            $this->fail('A store holding stock cannot be deactivated.');
        } catch (FacilityException $e) {
            $this->assertStringContainsString('still holds stock', $e->getMessage());
        }

        $this->ledger->issue($this->surfactant, $this->rudrapurRm, '5', InventoryLot::query()->where('item_id', $this->surfactant->id)->first(), InventoryTransactionType::StockAdjustmentOut, userId: $this->owner->id);
        $stores->deactivate($this->rudrapurRm, $this->owner->id);

        $this->assertFalse($this->rudrapurRm->fresh()->is_active);

        // A store nobody ever used can go.
        $unused = Warehouse::factory()->atFacility($this->rudrapur)->ofType(WarehouseType::General)->create();
        $stores->delete($unused);
        $this->assertSoftDeleted('warehouses', ['id' => $unused->id]);
    }

    // ---- Opening stock ---------------------------------------------------------

    #[Test]
    public function opening_stock_is_booked_through_the_ledger_and_can_be_switched_off(): void
    {
        $opening = app(OpeningStockService::class);

        $transaction = $opening->book($this->delhiFg, [
            ['item_id' => $this->product->id, 'quantity' => '120', 'batch_number' => 'FG-OPEN-01', 'manufactured_at' => '2026-08-01', 'unit_cost' => '45.50', 'remarks' => 'Counted on go-live'],
        ], $this->delhiStorekeeper->id, '2026-09-01');

        $this->assertSame(InventoryTransactionType::OpeningBalance, $transaction->type);
        $this->assertSame('120.000000', app(StockBalanceService::class)->onHand($this->product, $this->delhiFg)->__toString());
        $this->assertDatabaseHas('inventory_lots', ['item_id' => $this->product->id, 'batch_number' => 'FG-OPEN-01', 'unit_cost' => '45.500000']);

        app(FacilityService::class)->setOpeningStock($this->delhi, false, $this->owner->id);

        $this->expectException(OpeningStockException::class);
        $opening->book($this->delhiFg, [['item_id' => $this->product->id, 'quantity' => '1']], $this->delhiStorekeeper->id);
    }

    private function stock(Warehouse $store, $item, string $quantity): void
    {
        $lot = InventoryLot::factory()->forItem($item)->create(['qc_status' => LotQcStatus::Approved]);
        $this->ledger->receive($item, $store, $quantity, $lot, userId: $this->owner->id);
    }
}
