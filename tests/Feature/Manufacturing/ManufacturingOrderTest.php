<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Formulation\Services\FormulaService;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\InventoryTransaction;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\Manufacturing\Enums\ManufacturingOrderStatus;
use App\Domain\Manufacturing\Exceptions\ManufacturingException;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Manufacturing\Services\ManufacturingOrderService;
use App\Domain\MasterData\Models\PackagingMaterial;
use App\Domain\MasterData\Models\Product;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Planning\Enums\ProductionPlanStatus;
use App\Domain\Planning\Models\ProductionPlan;
use App\Domain\Planning\Services\ProductionPlanService;
use App\Domain\Quality\Models\QcInspection;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ManufacturingOrderTest extends TestCase
{
    use RefreshDatabase;

    private ManufacturingOrderService $orders;

    private ProductionPlanService $plans;

    private InventoryLedgerService $ledger;

    private StockBalanceService $balances;

    private User $productionManager;

    private User $factoryManager;

    private Warehouse $rmStore;

    private Warehouse $pmStore;

    private Warehouse $fgStore;

    private Warehouse $quarantine;

    private RawMaterial $surfactant;

    private RawMaterial $water;

    private PackagingMaterial $bottle;

    private Product $product;

    private Formula $formula;

    private Uom $kg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->orders = app(ManufacturingOrderService::class);
        $this->plans = app(ProductionPlanService::class);
        $this->ledger = app(InventoryLedgerService::class);
        $this->balances = app(StockBalanceService::class);

        $this->productionManager = User::factory()->create();
        $this->productionManager->assignRole(RoleName::ProductionManager->value);
        $this->factoryManager = User::factory()->create();
        $this->factoryManager->assignRole(RoleName::FactoryManager->value);

        $this->rmStore = Warehouse::factory()->create(['code' => 'WH-RM', 'type' => WarehouseType::RawMaterial]);
        $this->pmStore = Warehouse::factory()->create(['code' => 'WH-PM', 'type' => WarehouseType::Packaging]);
        $this->fgStore = Warehouse::factory()->create(['code' => 'WH-FG', 'type' => WarehouseType::FinishedGoods]);
        $this->quarantine = Warehouse::factory()->quarantine()->create(['code' => 'WH-QA']);

        $this->kg = Uom::where('code', 'KG')->firstOrFail();
        $ml = Uom::where('code', 'ML')->firstOrFail();
        $pcs = Uom::where('code', 'PCS')->firstOrFail();

        $this->surfactant = RawMaterial::factory()->create(['name' => 'Test Surfactant', 'stock_uom_id' => $this->kg->id, 'reorder_level' => null, 'minimum_stock' => null]);
        $this->water = RawMaterial::factory()->create(['name' => 'Purified Water', 'stock_uom_id' => $this->kg->id, 'density_g_per_ml' => '1', 'reorder_level' => null, 'minimum_stock' => null]);
        $this->bottle = PackagingMaterial::factory()->create(['name' => 'Bottle 100 ml', 'stock_uom_id' => $pcs->id, 'reorder_level' => null, 'minimum_stock' => null]);

        $this->product = Product::factory()->create([
            'name' => 'Test Face Wash 100 ml', 'net_content' => '100', 'net_content_uom_id' => $ml->id, 'stock_uom_id' => $pcs->id,
            'requires_qc' => true, 'shelf_life_days' => 730,
        ]);
        $this->product->packagingLines()->create(['packaging_material_id' => $this->bottle->id, 'quantity_per_unit' => '1']);

        $formulas = app(FormulaService::class);
        $this->formula = $formulas->create([
            'name' => 'Test Face Wash', 'product_id' => $this->product->id, 'batch_uom_id' => Uom::where('code', 'G')->value('id'),
        ], [
            ['item_id' => $this->surfactant->id, 'percentage' => '15'],
            ['item_id' => $this->water->id, 'is_qs' => true],
        ], $this->factoryManager->id);
        $formulas->activate($this->formula->versions()->first(), $this->factoryManager->id);
        $this->formula->refresh();
    }

    private function stockTheStores(string $surfactantKg = '20', string $waterKg = '200', string $bottles = '2000'): void
    {
        $older = InventoryLot::factory()->forItem($this->surfactant)->create(['qc_status' => LotQcStatus::Approved, 'batch_number' => 'RM-OLD', 'expiry_at' => now()->addMonths(3)]);
        $newer = InventoryLot::factory()->forItem($this->surfactant)->create(['qc_status' => LotQcStatus::Approved, 'batch_number' => 'RM-NEW', 'expiry_at' => now()->addMonths(9)]);

        $this->ledger->receive($this->surfactant, $this->rmStore, '5', $older, userId: $this->factoryManager->id);
        $this->ledger->receive($this->surfactant, $this->rmStore, (string) ((float) $surfactantKg - 5), $newer, userId: $this->factoryManager->id);
        $this->ledger->receive($this->water, $this->rmStore, $waterKg, userId: $this->factoryManager->id);
        $this->ledger->receive($this->bottle, $this->pmStore, $bottles, userId: $this->factoryManager->id);
    }

    private function plan(string $quantity = '100'): ProductionPlan
    {
        return $this->plans->create(['formula_id' => $this->formula->id, 'quantity' => $quantity, 'uom_id' => $this->kg->id], $this->productionManager->id);
    }

    #[Test]
    public function an_order_takes_its_material_list_from_the_plan(): void
    {
        $plan = $this->plan('100');

        $order = $this->orders->createFromPlan($plan, $this->productionManager->id);

        $this->assertMatchesRegularExpression('/^MO-\d{4}-00001$/', $order->number);
        $this->assertSame(ManufacturingOrderStatus::Draft, $order->status);
        $this->assertSame($plan->formula_version_id, $order->formula_version_id);
        $this->assertSame(1000, $order->planned_units);
        $this->assertCount(3, $order->lines);
        $this->assertSame('15.000000', $order->lines->firstWhere('item_id', $this->surfactant->id)->planned_quantity);
        $this->assertSame('1000.000000', $order->lines->firstWhere('item_id', $this->bottle->id)->planned_quantity);

        $this->expectException(ManufacturingException::class);
        $this->expectExceptionMessage('already has an open manufacturing order');
        $this->orders->createFromPlan($plan, $this->productionManager->id);
    }

    #[Test]
    public function approval_holds_nothing_when_any_material_is_short(): void
    {
        $this->stockTheStores(surfactantKg: '10');
        $order = $this->orders->createFromPlan($this->plan('100'), $this->productionManager->id);

        try {
            $this->orders->approve($order, $this->factoryManager->id);
            $this->fail('Approval succeeded with a short material.');
        } catch (ManufacturingException $e) {
            $this->assertStringContainsString('Quantity not available', $e->getMessage());
            $this->assertStringContainsString('Test Surfactant', $e->getMessage());
        }

        $this->assertSame(ManufacturingOrderStatus::Draft, $order->fresh()->status);
        $this->assertSame(0, StockReservation::count(), 'All or nothing: water was not held either');
        $this->assertTrue($this->balances->reserved($this->water, $this->rmStore)->isZero());
    }

    #[Test]
    public function approval_holds_every_material_by_earliest_expiry(): void
    {
        $this->stockTheStores();
        $plan = $this->plan('100');
        $order = $this->orders->createFromPlan($plan, $this->productionManager->id);

        $order = $this->orders->approve($order, $this->factoryManager->id);

        $this->assertSame(ManufacturingOrderStatus::Approved, $order->status);
        $this->assertSame(ProductionPlanStatus::InProduction, $plan->fresh()->status);

        $held = $order->reservations()->active()->where('item_id', $this->surfactant->id)->orderBy('id')->get();
        $this->assertCount(2, $held, 'The 15 kg spans two batches');
        $this->assertSame('RM-OLD', $held[0]->lot->batch_number);
        $this->assertSame('5.000000', $held[0]->quantity);
        $this->assertSame('10.000000', $held[1]->quantity);

        $this->assertSame('15.000000', $order->lines->firstWhere('item_id', $this->surfactant->id)->reserved_quantity);
        $this->assertTrue($this->balances->reserved($this->surfactant, $this->rmStore)->isEqualTo('15'));
        $this->assertTrue($this->balances->available($this->surfactant, $this->rmStore)->isEqualTo('5'));
        $this->assertTrue($this->balances->reserved($this->bottle, $this->pmStore)->isEqualTo('1000'));
    }

    #[Test]
    public function starting_issues_the_raw_materials_through_the_ledger(): void
    {
        $this->stockTheStores();
        $order = $this->orders->approve($this->orders->createFromPlan($this->plan('100'), $this->productionManager->id), $this->factoryManager->id);

        $order = $this->orders->start($order, $this->productionManager->id);

        $this->assertSame(ManufacturingOrderStatus::InProgress, $order->status);
        $this->assertNotNull($order->started_at);

        $this->assertTrue($this->balances->onHand($this->surfactant, $this->rmStore)->isEqualTo('5'));
        $this->assertTrue($this->balances->reserved($this->surfactant, $this->rmStore)->isZero());
        $this->assertTrue($this->balances->onHand($this->water, $this->rmStore)->isEqualTo('115'));
        $this->assertSame('15.000000', $order->lines->firstWhere('item_id', $this->surfactant->id)->consumed_quantity);

        // Packaging is still only held.
        $this->assertTrue($this->balances->onHand($this->bottle, $this->pmStore)->isEqualTo('2000'));
        $this->assertTrue($this->balances->reserved($this->bottle, $this->pmStore)->isEqualTo('1000'));

        $this->assertSame(3, InventoryTransaction::where('type', InventoryTransactionType::ProductionConsumption->value)
            ->where('reference_type', $order->getMorphClass())->where('reference_id', $order->id)->count(), 'One posting per batch drawn: two of surfactant, one of water');
    }

    #[Test]
    public function completing_uses_the_packaging_and_posts_the_batch_to_quarantine_for_qc(): void
    {
        $this->stockTheStores();
        $plan = $this->plan('100');
        $order = $this->orders->start($this->orders->approve($this->orders->createFromPlan($plan, $this->productionManager->id), $this->factoryManager->id), $this->productionManager->id);

        $order = $this->orders->complete($order, $this->productionManager->id, [
            'output_quantity' => '98.5',
            'output_units' => 980,
            'manufactured_at' => now()->toDateString(),
        ]);

        $this->assertSame(ManufacturingOrderStatus::Completed, $order->status);
        $this->assertSame('98.500', $order->yield_percentage);
        $this->assertSame(980, $order->output_units);
        $this->assertSame(ProductionPlanStatus::Completed, $plan->fresh()->status);

        $lot = $order->outputLot;
        $this->assertNotNull($lot);
        $this->assertMatchesRegularExpression('/^FG\d{6}-001$/', $lot->batch_number);
        $this->assertSame($this->product->id, $lot->item_id);
        $this->assertSame(LotQcStatus::Pending, $lot->qc_status);
        $this->assertSame(now()->addDays(730)->toDateString(), $lot->expiry_at->toDateString());
        $this->assertSame($order->getMorphClass(), $lot->source_type);

        // 980 pieces sit in quarantine with an inspection open for the finished goods store.
        $this->assertTrue($this->balances->onHand($this->product, $this->quarantine)->isEqualTo('980'));
        $this->assertTrue($this->balances->onHand($this->product, $this->fgStore)->isZero());
        $inspection = QcInspection::where('lot_id', $lot->id)->firstOrFail();
        $this->assertSame($this->fgStore->id, $inspection->destination_warehouse_id);

        // Packaging consumed, nothing left held.
        $this->assertTrue($this->balances->onHand($this->bottle, $this->pmStore)->isEqualTo('1000'));
        $this->assertSame(0, StockReservation::active()->count());
        $this->assertSame('1000.000000', $order->lines->firstWhere('item_id', $this->bottle->id)->consumed_quantity);
    }

    #[Test]
    public function a_product_that_needs_no_qc_lands_straight_in_the_finished_goods_store(): void
    {
        $this->product->forceFill(['requires_qc' => false])->save();
        $this->stockTheStores();
        $order = $this->orders->start($this->orders->approve($this->orders->createFromPlan($this->plan('100'), $this->productionManager->id), $this->factoryManager->id), $this->productionManager->id);

        $order = $this->orders->complete($order, $this->productionManager->id, ['output_quantity' => '100', 'output_units' => 1000]);

        $this->assertSame(LotQcStatus::NotRequired, $order->outputLot->qc_status);
        $this->assertTrue($this->balances->onHand($this->product, $this->fgStore)->isEqualTo('1000'));
        $this->assertSame(0, QcInspection::count());
    }

    #[Test]
    public function a_product_stocked_by_the_piece_needs_the_units_packed(): void
    {
        $this->stockTheStores();
        $order = $this->orders->start($this->orders->approve($this->orders->createFromPlan($this->plan('100'), $this->productionManager->id), $this->factoryManager->id), $this->productionManager->id);

        $this->expectException(ManufacturingException::class);
        $this->expectExceptionMessage('how many units were packed');

        $this->orders->complete($order, $this->productionManager->id, ['output_quantity' => '100']);
    }

    #[Test]
    public function cancelling_releases_what_is_held_and_keeps_what_was_used(): void
    {
        $this->stockTheStores();
        $plan = $this->plan('100');
        $order = $this->orders->approve($this->orders->createFromPlan($plan, $this->productionManager->id), $this->factoryManager->id);

        $this->orders->cancel($order, $this->productionManager->id, 'Kettle down');

        $this->assertSame(ManufacturingOrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(0, StockReservation::active()->count());
        $this->assertTrue($this->balances->available($this->surfactant, $this->rmStore)->isEqualTo('20'));
        $this->assertSame(ProductionPlanStatus::Checked, $plan->fresh()->status, 'The plan goes back to where it was');

        // A second order can now be opened; cancelling after a start keeps the consumption.
        $second = $this->orders->start($this->orders->approve($this->orders->createFromPlan($plan, $this->productionManager->id), $this->factoryManager->id), $this->productionManager->id);
        $this->orders->cancel($second, $this->productionManager->id);

        $this->assertTrue($this->balances->onHand($this->surfactant, $this->rmStore)->isEqualTo('5'), 'Issued raw material does not come back');
        $this->assertTrue($this->balances->reserved($this->bottle, $this->pmStore)->isZero(), 'Held packaging is released');
    }

    #[Test]
    public function the_lifecycle_refuses_steps_out_of_order(): void
    {
        $this->stockTheStores();
        $order = $this->orders->createFromPlan($this->plan('100'), $this->productionManager->id);

        try {
            $this->orders->start($order, $this->productionManager->id);
            $this->fail('A draft was started.');
        } catch (ManufacturingException $e) {
            $this->assertStringContainsString('only an approved order can be started', $e->getMessage());
        }

        $this->expectException(ManufacturingException::class);
        $this->expectExceptionMessage('only an order in progress can be completed');
        $this->orders->complete($order, $this->productionManager->id, ['output_quantity' => '1']);
    }

    // ---- Through the screens ---------------------------------------------

    #[Test]
    public function the_plant_runs_a_batch_from_the_screens(): void
    {
        $this->stockTheStores();
        $plan = $this->plan('100');

        $this->actingAs($this->productionManager)->post(route('manufacturing.store', $plan))->assertRedirect();
        $order = ManufacturingOrder::firstOrFail();

        // A production manager cannot approve; the factory manager can.
        $this->actingAs($this->productionManager)->post(route('manufacturing.approve', $order))->assertForbidden();
        $this->actingAs($this->factoryManager)->post(route('manufacturing.approve', $order))->assertRedirect();
        $this->assertSame(ManufacturingOrderStatus::Approved, $order->fresh()->status);

        $this->actingAs($this->productionManager)->post(route('manufacturing.start', $order))->assertRedirect();
        $this->assertSame(ManufacturingOrderStatus::InProgress, $order->fresh()->status);

        $this->actingAs($this->productionManager)->get(route('manufacturing.show', $order))->assertOk()->assertSee($order->number)->assertSee('RM-OLD');

        $this->actingAs($this->productionManager)
            ->post(route('manufacturing.complete', $order), ['output_quantity' => '99', 'output_units' => '990', 'manufactured_at' => now()->toDateString()])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(ManufacturingOrderStatus::Completed, $order->status);
        $this->assertNotNull($order->output_lot_id);

        $this->actingAs($this->productionManager)->get(route('manufacturing.index', ['status' => 'all']))->assertOk()->assertSee($order->number);
        $this->actingAs($this->productionManager)->get(route('plans.show', $plan))->assertOk()->assertSee($order->number);
    }

    #[Test]
    public function a_designer_sees_nothing_of_manufacturing_and_a_viewer_only_looks(): void
    {
        $designer = User::factory()->create();
        $designer->assignRole(RoleName::Designer->value);
        $this->actingAs($designer)->get(route('manufacturing.index'))->assertForbidden();

        $this->stockTheStores();
        $order = $this->orders->createFromPlan($this->plan('10'), $this->productionManager->id);

        $viewer = User::factory()->create();
        $viewer->assignRole(RoleName::Viewer->value);
        $this->actingAs($viewer)->get(route('manufacturing.show', $order))->assertOk();
        $this->actingAs($viewer)->post(route('manufacturing.approve', $order))->assertForbidden();
        $this->actingAs($viewer)->post(route('manufacturing.cancel', $order))->assertForbidden();
    }
}
