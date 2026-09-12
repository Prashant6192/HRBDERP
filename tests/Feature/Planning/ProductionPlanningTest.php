<?php

declare(strict_types=1);

namespace Tests\Feature\Planning;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Formulation\Services\FormulaService;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Enums\StockAlertLevel;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\MasterData\Models\PackagingMaterial;
use App\Domain\MasterData\Models\Product;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Planning\Enums\MaterialRequestStatus;
use App\Domain\Planning\Enums\ProductionPlanStatus;
use App\Domain\Planning\Enums\StoreKind;
use App\Domain\Planning\Exceptions\PlanningException;
use App\Domain\Planning\Models\MaterialRequest;
use App\Domain\Planning\Models\ProductionPlan;
use App\Domain\Planning\Services\ProductionPlanService;
use App\Domain\Procurement\Services\GoodsReceiptService;
use App\Domain\Quality\Models\QcInspection;
use App\Domain\Quality\Services\QcInspectionService;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductionPlanningTest extends TestCase
{
    use RefreshDatabase;

    private ProductionPlanService $plans;

    private User $productionManager;

    private User $purchaseManager;

    private User $director;

    private Facility $facility;

    private Warehouse $rmStore;

    private Warehouse $pmStore;

    private Warehouse $quarantine;

    private RawMaterial $surfactant;

    private RawMaterial $water;

    private PackagingMaterial $bottle;

    private PackagingMaterial $cap;

    private PackagingMaterial $carton;

    private Product $product;

    private Formula $formula;

    private Uom $kg;

    private Uom $litre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->plans = app(ProductionPlanService::class);

        $this->productionManager = User::factory()->create();
        $this->productionManager->assignRole(RoleName::ProductionManager->value);
        $this->purchaseManager = User::factory()->create();
        $this->purchaseManager->assignRole(RoleName::PurchaseManager->value);
        $this->director = User::factory()->create();
        $this->director->assignRole(RoleName::Director->value);

        $this->facility = Facility::factory()->manufacturing()->create(['code' => 'FAC-TST-001', 'name' => 'Test Plant']);
        $this->rmStore = Warehouse::factory()->atFacility($this->facility)->create(['code' => 'WH-RM', 'type' => WarehouseType::RawMaterial]);
        $this->pmStore = Warehouse::factory()->atFacility($this->facility)->create(['code' => 'WH-PM', 'type' => WarehouseType::Packaging]);
        $this->quarantine = Warehouse::factory()->atFacility($this->facility)->quarantine()->create(['code' => 'WH-QA']);

        $this->kg = Uom::where('code', 'KG')->firstOrFail();
        $this->litre = Uom::where('code', 'L')->firstOrFail();
        $ml = Uom::where('code', 'ML')->firstOrFail();
        $pcs = Uom::where('code', 'PCS')->firstOrFail();

        $this->surfactant = RawMaterial::factory()->create([
            'name' => 'Test Surfactant', 'stock_uom_id' => $this->kg->id, 'reorder_level' => '20', 'minimum_stock' => '10', 'requires_qc' => true,
        ]);
        $this->water = RawMaterial::factory()->create([
            'name' => 'Purified Water', 'stock_uom_id' => $this->kg->id, 'density_g_per_ml' => '1', 'reorder_level' => null, 'minimum_stock' => null, 'requires_qc' => false,
        ]);

        $this->bottle = PackagingMaterial::factory()->create(['name' => 'Bottle 100 ml', 'stock_uom_id' => $pcs->id, 'reorder_level' => '500', 'minimum_stock' => '200']);
        $this->cap = PackagingMaterial::factory()->create(['name' => 'Cap', 'stock_uom_id' => $pcs->id, 'reorder_level' => null, 'minimum_stock' => null]);
        $this->carton = PackagingMaterial::factory()->create(['name' => 'Shipper carton', 'stock_uom_id' => $pcs->id, 'reorder_level' => null, 'minimum_stock' => null]);

        $this->product = Product::factory()->create([
            'name' => 'Test Face Wash 100 ml', 'net_content' => '100', 'net_content_uom_id' => $ml->id, 'density_g_per_ml' => null,
        ]);
        $this->product->packagingLines()->createMany([
            ['packaging_material_id' => $this->bottle->id, 'quantity_per_unit' => '1'],
            ['packaging_material_id' => $this->cap->id, 'quantity_per_unit' => '1'],
            ['packaging_material_id' => $this->carton->id, 'quantity_per_unit' => '0.01'],
        ]);

        $formulas = app(FormulaService::class);
        $this->formula = $formulas->create([
            'name' => 'Test Face Wash',
            'product_id' => $this->product->id,
            'batch_uom_id' => Uom::where('code', 'G')->value('id'),
        ], [
            ['item_id' => $this->surfactant->id, 'percentage' => '15'],
            ['item_id' => $this->water->id, 'is_qs' => true],
        ], $this->director->id);
        $formulas->activate($this->formula->versions()->first(), $this->director->id);
        $this->formula->refresh();

        // 10 kg of surfactant, QC-approved, in the raw material store; 100 bottles in packaging.
        $ledger = app(InventoryLedgerService::class);
        $lot = InventoryLot::factory()->forItem($this->surfactant)->create(['qc_status' => LotQcStatus::Approved]);
        $ledger->receive($this->surfactant, $this->rmStore, '10', $lot, userId: $this->director->id);
        $ledger->receive($this->bottle, $this->pmStore, '100', userId: $this->director->id);
    }

    private function plan(string $quantity = '100', ?Uom $uom = null): ProductionPlan
    {
        return $this->plans->create([
            'formula_id' => $this->formula->id,
            'quantity' => $quantity,
            'uom_id' => ($uom ?? $this->kg)->id,
            'planned_start_date' => now()->addDays(7)->toDateString(),
        ], $this->productionManager->id);
    }

    #[Test]
    public function a_plan_is_checked_against_both_stores_the_moment_it_is_raised(): void
    {
        $plan = $this->plan('100');

        $this->assertMatchesRegularExpression('/^PLN-\d{4}-\d{5}$/', $plan->number);
        $this->assertSame(ProductionPlanStatus::Checked, $plan->status);
        $this->assertSame($this->formula->active_version_id, $plan->formula_version_id);
        $this->assertNotNull($plan->checked_at);

        $rm = $plan->lines->where('store_kind', StoreKind::RawMaterial)->values();
        $this->assertCount(2, $rm);

        $surfactant = $rm->firstWhere('item_id', $this->surfactant->id);
        $this->assertSame('15.000000', $surfactant->required_quantity);
        $this->assertSame('10.000000', $surfactant->available_quantity);
        $this->assertSame('5.000000', $surfactant->shortage_quantity);
        $this->assertSame(StockAlertLevel::Critical, $surfactant->level_now, '10 kg is at the 10 kg minimum');
        $this->assertSame(StockAlertLevel::OutOfStock, $surfactant->level_after);
        // Short 5, and 20 more to be back at the reorder level afterwards.
        $this->assertSame('25.000000', $surfactant->restock_quantity);

        $water = $rm->firstWhere('item_id', $this->water->id);
        $this->assertTrue($water->is_qs);
        $this->assertSame('85.000000', $water->required_quantity);
        $this->assertSame('85.000000', $water->shortage_quantity);
        $this->assertSame(StockAlertLevel::OutOfStock, $water->level_now);

        // 100 kg at 1 g/ml (no density on the product) = 100 000 ml = 1 000 units.
        $this->assertSame(1000, $plan->planned_units);

        $pm = $plan->lines->where('store_kind', StoreKind::Packaging)->values();
        $this->assertCount(3, $pm);

        $bottles = $pm->firstWhere('item_id', $this->bottle->id);
        $this->assertSame('1000.000000', $bottles->required_quantity);
        $this->assertSame('100.000000', $bottles->available_quantity);
        $this->assertSame('900.000000', $bottles->shortage_quantity);

        $cartons = $pm->firstWhere('item_id', $this->carton->id);
        $this->assertSame('10.000000', $cartons->required_quantity, '0.01 per unit, rounded up to whole cartons');

        $this->assertTrue($plan->hasShortage());
        $this->assertStringContainsString('1 g/ml', implode(' ', $plan->warnings));
    }

    #[Test]
    public function a_batch_the_store_can_cover_shows_no_shortage(): void
    {
        $ledger = app(InventoryLedgerService::class);
        $ledger->receive($this->water, $this->rmStore, '500', userId: $this->director->id);
        $ledger->receive($this->bottle, $this->pmStore, '5000', userId: $this->director->id);
        $ledger->receive($this->cap, $this->pmStore, '5000', userId: $this->director->id);
        $ledger->receive($this->carton, $this->pmStore, '50', userId: $this->director->id);

        $plan = $this->plan('20');

        $this->assertFalse($plan->hasShortage());

        $surfactant = $plan->lines->firstWhere('item_id', $this->surfactant->id);
        $this->assertSame('3.000000', $surfactant->required_quantity);
        $this->assertSame('0.000000', $surfactant->shortage_quantity);
        $this->assertSame(StockAlertLevel::Critical, $surfactant->level_now);
        $this->assertSame(StockAlertLevel::Critical, $surfactant->level_after, '7 kg left is still under the 10 kg minimum');
        $this->assertSame('13.000000', $surfactant->restock_quantity, 'Enough to be back at the 20 kg reorder level after the run');
    }

    #[Test]
    public function only_qc_released_stock_counts(): void
    {
        $ledger = app(InventoryLedgerService::class);
        $pending = InventoryLot::factory()->forItem($this->surfactant)->pendingQc()->create();
        $ledger->receive($this->surfactant, $this->quarantine, '50', $pending, userId: $this->director->id);

        $plan = $this->plan('100');

        $this->assertSame('10.000000', $plan->lines->firstWhere('item_id', $this->surfactant->id)->available_quantity);
    }

    #[Test]
    public function a_formula_without_an_active_recipe_cannot_be_planned(): void
    {
        $draftOnly = app(FormulaService::class)->create([
            'name' => 'Unfinished', 'batch_uom_id' => Uom::where('code', 'G')->value('id'),
        ], [['item_id' => $this->surfactant->id, 'percentage' => '1']], $this->director->id);

        $this->expectException(PlanningException::class);
        $this->expectExceptionMessage('no active version');

        $this->plans->create(['formula_id' => $draftOnly->id, 'quantity' => '10', 'uom_id' => $this->kg->id], $this->productionManager->id);
    }

    #[Test]
    public function raising_requests_makes_one_pmr_per_store(): void
    {
        $plan = $this->plan('100');

        $requests = $this->plans->generateRequests($plan, $this->productionManager->id);

        $this->assertCount(2, $requests);
        $this->assertSame(ProductionPlanStatus::Requested, $plan->fresh()->status);

        [$rm, $pm] = $requests;
        $this->assertMatchesRegularExpression('/^PMR-\d{4}-00001$/', $rm->number);
        $this->assertMatchesRegularExpression('/^PMR-\d{4}-00002$/', $pm->number);
        $this->assertSame(StoreKind::RawMaterial, $rm->store_kind);
        $this->assertSame($this->rmStore->id, $rm->warehouse_id);
        $this->assertSame(StoreKind::Packaging, $pm->store_kind);
        $this->assertSame($this->pmStore->id, $pm->warehouse_id);
        $this->assertSame(now()->addDays(7)->toDateString(), $rm->needed_by->toDateString());

        $line = $rm->lines->firstWhere('item_id', $this->surfactant->id);
        $this->assertSame('15.000000', $line->required_quantity);
        $this->assertSame('5.000000', $line->quantity_to_order);
        $this->assertSame('25.000000', $line->restock_quantity);
        $this->assertSame(StockAlertLevel::Critical, $line->alert_level);

        $this->assertCount(3, $pm->lines);

        $this->expectException(PlanningException::class);
        $this->expectExceptionMessage('already has its material requests');
        $this->plans->generateRequests($plan, $this->productionManager->id);
    }

    #[Test]
    public function a_delivery_booked_in_against_a_pmr_closes_its_lines(): void
    {
        $plan = $this->plan('100');
        [$rm] = $this->plans->generateRequests($plan, $this->productionManager->id);

        $receipts = app(GoodsReceiptService::class);
        $receipt = $receipts->create([
            'warehouse_id' => $this->rmStore->id,
            'material_request_id' => $rm->id,
            'received_at' => now()->toDateString(),
        ], [
            ['item_id' => $this->surfactant->id, 'quantity' => '5', 'uom_id' => $this->kg->id],
        ], $this->director->id);
        $receipts->post($receipt, $this->director->id);

        $rm->refresh();
        $this->assertSame(MaterialRequestStatus::PartiallyReceived, $rm->status);
        $this->assertSame('5.000000', $rm->lines->firstWhere('item_id', $this->surfactant->id)->received_quantity);
        $this->assertTrue($rm->lines->firstWhere('item_id', $this->surfactant->id)->isCovered());

        $receipt = $receipts->create([
            'warehouse_id' => $this->rmStore->id,
            'material_request_id' => $rm->id,
            'received_at' => now()->toDateString(),
        ], [
            ['item_id' => $this->water->id, 'quantity' => '85', 'uom_id' => $this->kg->id],
        ], $this->director->id);
        $receipts->post($receipt, $this->director->id);

        $rm->refresh();
        $this->assertSame(MaterialRequestStatus::Fulfilled, $rm->status);
        $this->assertNotNull($rm->fulfilled_at);

        // Once QC releases the surfactant, a re-check finds the store covered.
        $inspection = QcInspection::query()->where('item_id', $this->surfactant->id)->firstOrFail();
        app(QcInspectionService::class)->approve($inspection, $this->director->id);

        $plan = $this->plans->check($plan);
        $this->assertFalse($plan->lines->where('store_kind', StoreKind::RawMaterial)->contains(fn ($l) => $l->shortage()->isPositive()));
        $this->assertSame(ProductionPlanStatus::Requested, $plan->status, 'Re-checking does not lose the requests');
    }

    #[Test]
    public function cancelling_a_plan_cancels_its_open_requests(): void
    {
        $plan = $this->plan('100');
        $this->plans->generateRequests($plan, $this->productionManager->id);

        $this->plans->cancel($plan, $this->productionManager->id, 'Order withdrawn');

        $plan->refresh();
        $this->assertSame(ProductionPlanStatus::Cancelled, $plan->status);
        $this->assertStringContainsString('Order withdrawn', $plan->notes);
        $this->assertSame(2, MaterialRequest::where('status', MaterialRequestStatus::Cancelled->value)->count());

        $this->expectException(PlanningException::class);
        $this->plans->check($plan);
    }

    #[Test]
    public function a_formula_without_a_packaging_list_raises_only_the_raw_material_request(): void
    {
        $this->product->packagingLines()->delete();

        $plan = $this->plan('10');

        $this->assertCount(0, $plan->lines->where('store_kind', StoreKind::Packaging));
        $this->assertStringContainsString('no packaging list', implode(' ', $plan->warnings));

        $requests = $this->plans->generateRequests($plan, $this->productionManager->id);
        $this->assertCount(1, $requests);
        $this->assertSame(StoreKind::RawMaterial, $requests->first()->store_kind);
    }

    // ---- Through the screens ---------------------------------------------

    #[Test]
    public function a_production_manager_plans_a_batch_from_the_screen(): void
    {
        $response = $this->actingAs($this->productionManager)->post(route('plans.store'), [
            'formula_id' => $this->formula->id,
            'quantity' => '50',
            'uom_id' => $this->kg->id,
        ]);

        $plan = ProductionPlan::firstOrFail();
        $response->assertRedirect(route('plans.show', $plan));

        $this->actingAs($this->productionManager)->get(route('plans.show', $plan))
            ->assertOk()
            ->assertSee($plan->number)
            ->assertSee('Test Surfactant');

        $this->actingAs($this->productionManager)->post(route('plans.requests', $plan))->assertRedirect();
        $this->assertSame(2, $plan->materialRequests()->count());
    }

    #[Test]
    public function a_viewer_cannot_plan_and_a_purchase_manager_can_only_look(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole(RoleName::Viewer->value);

        $this->actingAs($viewer)->get(route('plans.index'))->assertForbidden();

        $plan = $this->plan('10');
        $this->actingAs($this->purchaseManager)->get(route('plans.show', $plan))->assertOk();
        $this->actingAs($this->purchaseManager)->post(route('plans.requests', $plan))->assertForbidden();
    }

    #[Test]
    public function the_purchase_manager_sees_and_prints_a_material_request(): void
    {
        $plan = $this->plan('100');
        [$rm] = $this->plans->generateRequests($plan, $this->productionManager->id);

        $this->actingAs($this->purchaseManager)->get(route('material-requests.index'))->assertOk()->assertSee($rm->number);
        $this->actingAs($this->purchaseManager)->get(route('material-requests.show', $rm))->assertOk()->assertSee('Test Surfactant');

        $pdf = $this->actingAs($this->purchaseManager)->get(route('material-requests.pdf', $rm));
        $pdf->assertOk();
        $pdf->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
    }

    #[Test]
    public function the_packaging_list_is_edited_from_the_product_screen(): void
    {
        $label = PackagingMaterial::factory()->create(['name' => 'Front label']);

        // Products are owned by the business, not by the plant: the Director
        // only reads them. The Owner edits.
        $this->director = User::factory()->create();
        $this->director->assignRole(RoleName::Owner->value);

        $this->actingAs($this->director)
            ->post(route('products.packaging.store', $this->product), ['packaging_material_id' => $label->id, 'quantity_per_unit' => '1'])
            ->assertRedirect();

        $this->assertSame(4, $this->product->packagingLines()->count());

        $this->actingAs($this->director)
            ->post(route('products.packaging.store', $this->product), ['packaging_material_id' => $label->id, 'quantity_per_unit' => '1'])
            ->assertSessionHasErrors('packaging_material_id');

        $line = $this->product->packagingLines()->where('packaging_material_id', $label->id)->firstOrFail();

        $this->actingAs($this->director)
            ->delete(route('products.packaging.destroy', ['product' => $this->product, 'line' => $line]))
            ->assertRedirect();

        $this->assertSame(3, $this->product->packagingLines()->count());

        $this->actingAs($this->director)->get(route('products.show', $this->product))->assertOk()->assertSee('Bottle 100 ml');
    }

    #[Test]
    public function the_goods_receipt_screen_offers_open_requests(): void
    {
        $plan = $this->plan('100');
        [$rm] = $this->plans->generateRequests($plan, $this->productionManager->id);

        $warehouseManager = User::factory()->create();
        $warehouseManager->assignRole(RoleName::WarehouseManager->value);

        $this->actingAs($warehouseManager)
            ->get(route('goods-receipts.create', ['material_request' => $rm->id]))
            ->assertOk()
            ->assertSee($rm->number);
    }
}
