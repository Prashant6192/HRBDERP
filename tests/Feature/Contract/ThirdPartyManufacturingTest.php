<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Contract\Enums\ArtworkStatus;
use App\Domain\Contract\Models\Client;
use App\Domain\Contract\Models\ClientArtwork;
use App\Domain\Contract\Models\ClientQcSpec;
use App\Domain\Contract\Services\ClientMaterialReconciliationService;
use App\Domain\Contract\Services\JobCostingService;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Formulation\Services\FormulaService;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\Manufacturing\Enums\ManufacturingOrderStatus;
use App\Domain\Manufacturing\Exceptions\ManufacturingException;
use App\Domain\Manufacturing\Services\ManufacturingOrderService;
use App\Domain\MasterData\Models\Item;
use App\Domain\MasterData\Models\PackagingMaterial;
use App\Domain\MasterData\Models\Product;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Planning\Enums\StoreKind;
use App\Domain\Planning\Exceptions\PlanningException;
use App\Domain\Planning\Models\ProductionPlan;
use App\Domain\Planning\Services\ProductionPlanService;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Quality\Models\QcInspection;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A client's batch runs through the ordinary workflow: planned, checked
 * against the stores, made, QC'd and put away — tagged with the client at
 * every step, its material kept apart from ours and from other clients'.
 */
class ThirdPartyManufacturingTest extends TestCase
{
    use RefreshDatabase;

    private ProductionPlanService $plans;

    private ManufacturingOrderService $orders;

    private InventoryLedgerService $ledger;

    private StockBalanceService $balances;

    private User $factoryManager;

    private User $owner;

    private Facility $facility;

    private Warehouse $rmStore;

    private Warehouse $pmStore;

    private Warehouse $fgStore;

    private Client $abc;

    private Client $xyz;

    private RawMaterial $surfactant;

    private RawMaterial $fragrance;

    private RawMaterial $water;

    private PackagingMaterial $bottle;

    private PackagingMaterial $label;

    private Product $shampoo;

    private Formula $formula;

    private Uom $kg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');

        $this->plans = app(ProductionPlanService::class);
        $this->orders = app(ManufacturingOrderService::class);
        $this->ledger = app(InventoryLedgerService::class);
        $this->balances = app(StockBalanceService::class);

        $this->factoryManager = User::factory()->create(['name' => 'Suresh Patil']);
        $this->factoryManager->assignRole(RoleName::FactoryManager->value);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(RoleName::Owner->value);

        $this->facility = Facility::factory()->manufacturing()->create(['code' => 'FAC-RDP-001', 'name' => 'Rudrapur']);
        $this->rmStore = Warehouse::factory()->atFacility($this->facility)->create(['code' => 'RDP-RM', 'type' => WarehouseType::RawMaterial]);
        $this->pmStore = Warehouse::factory()->atFacility($this->facility)->create(['code' => 'RDP-PM', 'type' => WarehouseType::Packaging]);
        $this->fgStore = Warehouse::factory()->atFacility($this->facility)->create(['code' => 'RDP-FG', 'type' => WarehouseType::FinishedGoods]);
        Warehouse::factory()->atFacility($this->facility)->quarantine()->create(['code' => 'RDP-QA']);

        $this->abc = Client::factory()->create(['code' => 'TP-001', 'name' => 'ABC Wellness Pvt Ltd']);
        $this->xyz = Client::factory()->create(['code' => 'TP-002', 'name' => 'XYZ Cosmetics']);

        $this->kg = Uom::where('code', 'KG')->firstOrFail();
        $ml = Uom::where('code', 'ML')->firstOrFail();
        $pcs = Uom::where('code', 'PCS')->firstOrFail();

        $this->surfactant = RawMaterial::factory()->create(['name' => 'Surfactant A', 'stock_uom_id' => $this->kg->id, 'standard_cost' => '100', 'reorder_level' => null, 'minimum_stock' => null, 'requires_qc' => false]);
        $this->fragrance = RawMaterial::factory()->create(['name' => 'Premium Fragrance X', 'stock_uom_id' => $this->kg->id, 'standard_cost' => '2000', 'reorder_level' => null, 'minimum_stock' => null, 'requires_qc' => false]);
        $this->water = RawMaterial::factory()->create(['name' => 'Purified Water', 'stock_uom_id' => $this->kg->id, 'density_g_per_ml' => '1', 'standard_cost' => '1', 'reorder_level' => null, 'minimum_stock' => null, 'requires_qc' => false]);
        $this->bottle = PackagingMaterial::factory()->create(['name' => 'Bottle 200 ml', 'stock_uom_id' => $pcs->id, 'standard_cost' => '5', 'reorder_level' => null, 'minimum_stock' => null, 'requires_qc' => false]);
        $this->label = PackagingMaterial::factory()->create(['name' => 'ABC Label', 'stock_uom_id' => $pcs->id, 'standard_cost' => '1', 'reorder_level' => null, 'minimum_stock' => null, 'requires_qc' => false]);

        $this->shampoo = Product::factory()->create([
            'name' => 'Herbal Anti-Dandruff Shampoo 200 ml', 'net_content' => '200', 'net_content_uom_id' => $ml->id, 'stock_uom_id' => $pcs->id,
            'requires_qc' => false, 'shelf_life_days' => 730, 'client_id' => $this->abc->id,
        ]);
        $this->shampoo->packagingLines()->create(['packaging_material_id' => $this->bottle->id, 'quantity_per_unit' => '1']);
        $this->shampoo->packagingLines()->create(['packaging_material_id' => $this->label->id, 'quantity_per_unit' => '1']);

        $formulas = app(FormulaService::class);
        $this->formula = $formulas->create([
            'name' => 'Herbal Shampoo', 'product_id' => $this->shampoo->id, 'batch_uom_id' => Uom::where('code', 'G')->value('id'),
            'ownership' => 'joint', 'client_id' => $this->abc->id,
        ], [
            ['item_id' => $this->surfactant->id, 'percentage' => '15'],
            ['item_id' => $this->fragrance->id, 'percentage' => '1'],
            ['item_id' => $this->water->id, 'is_qs' => true],
        ], $this->factoryManager->id);
        $formulas->activate($this->formula->versions()->first(), $this->factoryManager->id);

        // Our own stock.
        $this->stock($this->surfactant, $this->rmStore, '500', '100');
        $this->stock($this->water, $this->rmStore, '5000', '1');
        $this->stock($this->bottle, $this->pmStore, '12000', '5');
    }

    // ---- Helpers -----------------------------------------------------------

    private function stock(Item $item, Warehouse $store, string $quantity, string $unitCost, ?Client $owner = null): InventoryLot
    {
        $lot = InventoryLot::factory()->forItem($item)->create([
            'qc_status' => LotQcStatus::Approved, 'unit_cost' => $unitCost, 'owner_client_id' => $owner?->id, 'initial_quantity' => $quantity,
        ]);
        $this->ledger->receive($item, $store, $quantity, $lot, unitCost: $unitCost, userId: $this->factoryManager->id);

        return $lot;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function plan(array $overrides = []): ProductionPlan
    {
        return $this->plans->create([
            'formula_id' => $this->formula->id,
            'facility_id' => $this->facility->id,
            'quantity' => '2000',
            'uom_id' => $this->kg->id,
            'manufacturing_type' => 'third_party',
            'client_id' => $this->abc->id,
            'client_po_ref' => 'ABC/PO/2026/145',
            'client_product_name' => 'Herbal Shampoo',
            'required_delivery_at' => now()->addDays(10)->toDateString(),
            'material_source' => 'mixed',
            'client_supplied_item_ids' => [$this->fragrance->id, $this->label->id],
            ...$overrides,
        ], $this->factoryManager->id);
    }

    private function line(ProductionPlan $plan, Item $item)
    {
        return $plan->lines()->where('item_id', $item->id)->firstOrFail();
    }

    // ---- The client master --------------------------------------------------

    #[Test]
    public function a_client_is_added_with_a_generated_code(): void
    {
        $this->actingAs($this->factoryManager)
            ->post(route('clients.store'), ['name' => 'Fine Organics Pvt Ltd', 'gstin' => '07AABCF1234A1ZK', 'payment_terms_days' => 45, 'billing_city' => 'Delhi'])
            ->assertRedirect();

        $client = Client::query()->where('name', 'Fine Organics Pvt Ltd')->sole();
        $this->assertSame('TP-003', $client->code);
        $this->assertTrue($client->is_active);

        $this->actingAs($this->factoryManager)
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('clients/show')->where('client.code', 'TP-003')->where('costing.jobs', 0));

        $this->actingAs($this->factoryManager)
            ->post(route('clients.store'), ['name' => 'Again', 'gstin' => '07AABCF1234A1ZK'])
            ->assertSessionHasErrors('gstin');
    }

    // ---- Formula ownership ---------------------------------------------------

    #[Test]
    public function a_clients_formula_is_only_ever_made_for_that_client(): void
    {
        try {
            $this->plan(['manufacturing_type' => 'own', 'client_id' => null]);
            $this->fail('An own-brand batch from a joint formula should be refused.');
        } catch (PlanningException $e) {
            $this->assertStringContainsString('ABC Wellness', $e->getMessage());
        }

        try {
            $this->plan(['client_id' => $this->xyz->id]);
            $this->fail('Another client cannot use it either.');
        } catch (PlanningException $e) {
            $this->assertStringContainsString('only be made for that client', $e->getMessage());
        }

        $plan = $this->plan();
        $this->assertTrue($plan->isThirdParty());
        $this->assertSame('ABC/PO/2026/145', $plan->client_po_ref);
    }

    // ---- Material ownership ---------------------------------------------------

    #[Test]
    public function client_supplied_material_counts_only_the_clients_own_stock(): void
    {
        $plan = $this->plan();

        $fragrance = $this->line($plan, $this->fragrance);
        $this->assertSame('client', $fragrance->source);
        $this->assertSame('20.000000', $fragrance->required_quantity, '1% of 2,000 kg');
        $this->assertSame('0.000000', $fragrance->available_quantity, 'Nothing from the client yet.');
        $this->assertSame('20.000000', $fragrance->shortage_quantity);
        $this->assertStringContainsString('Awaiting client material', implode(' ', $fragrance->notes ?? []));

        $surfactant = $this->line($plan, $this->surfactant);
        $this->assertSame('company', $surfactant->source);
        $this->assertSame('300.000000', $surfactant->required_quantity);
        $this->assertSame('500.000000', $surfactant->available_quantity, 'Our stock covers our line.');

        // The client's fragrance arrives on a goods receipt in their name.
        $this->actingAs($this->factoryManager)->post(route('goods-receipts.store'), [
            'warehouse_id' => $this->rmStore->id,
            'owner_client_id' => $this->abc->id,
            'received_at' => now()->toDateString(),
            'invoice_ref' => 'ABC-DC-77',
            'post_now' => true,
            'lines' => [['item_id' => $this->fragrance->id, 'quantity' => '30', 'uom_id' => $this->kg->id, 'unit_price' => '2000']],
        ])->assertRedirect();

        $receipt = GoodsReceipt::sole();
        $lot = $receipt->lines->first()->lot;
        $this->assertSame($this->abc->id, $receipt->owner_client_id);
        $this->assertSame($this->abc->id, $lot->owner_client_id, 'The batch is the client\'s.');

        // Ours, the client's, and another client's are three different answers.
        $this->assertSame('0', $this->balances->availableForProduction($this->fragrance, [$this->rmStore->id])->__toString(), 'Not ours.');
        $this->assertSame('30.000000', $this->balances->availableForProduction($this->fragrance, [$this->rmStore->id], $this->abc->id)->__toString());
        $this->assertSame('0', $this->balances->availableForProduction($this->fragrance, [$this->rmStore->id], $this->xyz->id)->__toString(), 'Not XYZ\'s either.');
        $this->assertSame('30.000000', $this->balances->availableForProduction($this->fragrance, [$this->rmStore->id], anyOwner: true)->__toString());

        $plan = $this->plans->check($plan);
        $fragrance = $this->line($plan, $this->fragrance);
        $this->assertSame('30.000000', $fragrance->available_quantity);
        $this->assertSame('0.000000', $fragrance->shortage_quantity);

        // The labels are still to come from the client.
        $label = $this->line($plan, $this->label);
        $this->assertSame('client', $label->source);
        $this->assertTrue($label->shortage()->isPositive());

        // Requests carry the source, so the store knows what not to order.
        $requests = $this->plans->generateRequests($plan, $this->factoryManager->id);
        $pmLine = $requests->firstWhere('store_kind', StoreKind::Packaging)->lines()->where('item_id', $this->label->id)->sole();
        $this->assertSame('client', $pmLine->source);
    }

    #[Test]
    public function an_own_brand_batch_never_touches_a_clients_material(): void
    {
        $this->stock($this->fragrance, $this->rmStore, '30', '2000', $this->abc);

        $own = app(FormulaService::class)->create([
            'name' => 'Own Shampoo', 'product_id' => null, 'batch_uom_id' => Uom::where('code', 'G')->value('id'),
        ], [
            ['item_id' => $this->fragrance->id, 'percentage' => '1'],
            ['item_id' => $this->water->id, 'is_qs' => true],
        ], $this->factoryManager->id);
        app(FormulaService::class)->activate($own->versions()->first(), $this->factoryManager->id);

        $plan = $this->plans->create([
            'formula_id' => $own->id, 'facility_id' => $this->facility->id, 'quantity' => '1000', 'uom_id' => $this->kg->id,
        ], $this->factoryManager->id);

        $fragrance = $this->line($plan, $this->fragrance);
        $this->assertSame('company', $fragrance->source);
        $this->assertSame('0.000000', $fragrance->available_quantity, '30 kg of ABC\'s fragrance sits on the shelf and counts for nothing here.');
        $this->assertSame('10.000000', $fragrance->shortage_quantity);

        // Nor can another client's job take it.
        $xyzPlan = $this->plans->create([
            'formula_id' => $own->id, 'facility_id' => $this->facility->id, 'quantity' => '1000', 'uom_id' => $this->kg->id,
            'manufacturing_type' => 'third_party', 'client_id' => $this->xyz->id, 'material_source' => 'mixed', 'client_supplied_item_ids' => [$this->fragrance->id],
        ], $this->factoryManager->id);
        $this->assertSame('0.000000', $this->line($xyzPlan, $this->fragrance)->available_quantity);
    }

    // ---- The job ----------------------------------------------------------------

    #[Test]
    public function a_third_party_batch_uses_the_clients_material_and_is_posted_as_theirs(): void
    {
        $fragranceLot = $this->stock($this->fragrance, $this->rmStore, '30', '2000', $this->abc);
        $this->stock($this->label, $this->pmStore, '12000', '1', $this->abc);

        $plan = $this->plan();
        $this->assertFalse($plan->hasShortage());

        $order = $this->orders->createFromPlan($plan, $this->factoryManager->id);
        $this->assertTrue($order->isThirdParty());
        $this->assertSame([$this->fragrance->id, $this->label->id], $order->clientSuppliedItemIds());

        $order = $this->orders->approve($order, $this->factoryManager->id);
        $held = $order->reservations()->where('item_id', $this->fragrance->id)->get();
        $this->assertCount(1, $held);
        $this->assertSame($fragranceLot->id, $held->first()->lot_id, 'The fragrance is held from the client\'s own batch.');

        $order = $this->orders->start($order, $this->factoryManager->id);
        $order = $this->orders->complete($order, $this->factoryManager->id, ['output_quantity' => '2000', 'output_units' => 9800]);

        $this->assertSame(ManufacturingOrderStatus::Completed, $order->status);
        $lot = $order->outputLot;
        $this->assertSame($this->abc->id, $lot->owner_client_id, 'The finished goods are the client\'s.');
        $this->assertMatchesRegularExpression('/^TP-001-\d{6}-001$/', $lot->batch_number);
        $this->assertSame('9800.000000', $this->balances->onHand($this->shampoo, $this->fgStore)->__toString());
        $this->assertSame('0', $this->balances->availableForProduction($this->shampoo, [$this->fgStore->id])->__toString(), 'Not ours to sell.');

        // The client's material, reconciled.
        $rows = collect(app(ClientMaterialReconciliationService::class)->rows($this->abc, $order))->keyBy('item_id');
        $this->assertSame('30.000000', $rows[$this->fragrance->id]['supplied']);
        $this->assertSame('20.000000', $rows[$this->fragrance->id]['consumed_on_job']);
        $this->assertSame('10.000000', $rows[$this->fragrance->id]['balance']);
        $this->assertSame('10000.000000', $rows[$this->label->id]['consumed']);
        $this->assertSame('2000.000000', $rows[$this->label->id]['balance']);

        // The commercial terms and the job's costing.
        $this->actingAs($this->factoryManager)->put(route('manufacturing.terms', $order), [
            'manufacturing_rate' => '25', 'rate_basis' => 'per_quantity', 'bill_materials' => true, 'material_markup_pct' => '0',
            'testing' => '5000', 'gst_rate' => '18',
        ])->assertRedirect();

        $costing = app(JobCostingService::class)->forOrder($order->fresh());
        $this->assertSame('actual', $costing['basis']);
        $this->assertSame('31680.00', $costing['raw_material_cost'], '300 kg surfactant at 100 + 1,680 kg water at 1');
        $this->assertSame('50000.00', $costing['packaging_cost'], '10,000 bottles at 5');
        $this->assertSame('50000.00', $costing['client_material_value'], '20 kg fragrance at 2,000 + 10,000 labels at 1: theirs, not charged');
        $this->assertSame('50000.00', $costing['manufacturing_charge'], '25 per kg × 2,000 kg');
        $this->assertSame('136680.00', $costing['chargeable']);
        $this->assertSame('24602.40', $costing['gst']);
        $this->assertSame('161282.40', $costing['total']);
        $this->assertSame('55000.00', $costing['margin']);

        $this->actingAs($this->factoryManager)
            ->get(route('manufacturing.show', $order))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('thirdParty.client.name', 'ABC Wellness Pvt Ltd')
                ->where('thirdParty.costing.total', '161282.40')
                ->has('thirdParty.reconciliation', 2)
                ->where('can.terms', true)
            );

        $this->actingAs($this->factoryManager)
            ->get(route('clients.show', $this->abc))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('finishedGoods', 1)
                ->where('finishedGoods.0.batch_number', $lot->batch_number)
                ->where('costing.jobs', 1)
                ->where('costing.chargeable', '136680.00')
                ->has('material', 2)
            );
    }

    #[Test]
    public function approval_waits_for_the_clients_material(): void
    {
        $this->stock($this->label, $this->pmStore, '12000', '1', $this->abc);
        // Fragrance on the shelf, but ours, not the client's.
        $this->stock($this->fragrance, $this->rmStore, '30', '2000');

        $order = $this->orders->createFromPlan($this->plan(), $this->factoryManager->id);

        try {
            $this->orders->approve($order, $this->factoryManager->id);
            $this->fail('Approval should wait for the client\'s fragrance.');
        } catch (ManufacturingException $e) {
            $this->assertStringContainsString('Premium Fragrance X', $e->getMessage());
            $this->assertStringContainsString('awaiting client material', $e->getMessage());
        }

        $this->assertSame(0, $order->reservations()->count(), 'All or nothing: nothing was held.');
    }

    // ---- Screens ------------------------------------------------------------------

    #[Test]
    public function the_dashboard_and_the_lists_pick_out_third_party_work(): void
    {
        $this->stock($this->fragrance, $this->rmStore, '30', '2000', $this->abc);
        $this->stock($this->label, $this->pmStore, '12000', '1', $this->abc);

        $plan = $this->plan();
        $order = $this->orders->approve($this->orders->createFromPlan($plan, $this->factoryManager->id), $this->factoryManager->id);

        $ownFormula = app(FormulaService::class)->create(['name' => 'Own Wash', 'product_id' => null, 'batch_uom_id' => Uom::where('code', 'G')->value('id')], [
            ['item_id' => $this->surfactant->id, 'percentage' => '10'], ['item_id' => $this->water->id, 'is_qs' => true],
        ], $this->factoryManager->id);
        app(FormulaService::class)->activate($ownFormula->versions()->first(), $this->factoryManager->id);
        $this->plans->create(['formula_id' => $ownFormula->id, 'facility_id' => $this->facility->id, 'quantity' => '100', 'uom_id' => $this->kg->id], $this->factoryManager->id);

        $this->actingAs($this->factoryManager)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('thirdParty.active', 1)
                ->where('thirdParty.active.0.client', 'ABC Wellness Pvt Ltd')
                ->where('thirdParty.active.0.number', $order->number)
                ->has('thirdParty.upcoming', 1)
            );

        $this->actingAs($this->factoryManager)
            ->get(route('plans.index', ['type' => 'third_party']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('plans.data', 1)->where('plans.data.0.client.name', 'ABC Wellness Pvt Ltd'));

        $this->actingAs($this->factoryManager)
            ->get(route('plans.index', ['type' => 'own']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('plans.data', 1)->where('plans.data.0.client', null));

        $this->actingAs($this->factoryManager)
            ->get(route('manufacturing.index', ['client' => $this->abc->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('orders.data', 1));

        $this->actingAs($this->factoryManager)
            ->get(route('manufacturing.index', ['client' => $this->xyz->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('orders.data', 0));
    }

    #[Test]
    public function artwork_approvals_and_qc_specifications_are_kept_per_client(): void
    {
        $file = UploadedFile::fake()->createWithContent('label-v1.pdf', "%PDF-1.4\n");
        $file->mimeTypeToReport = 'application/pdf';

        $this->actingAs($this->factoryManager)->post(route('clients.artworks.store', $this->abc), [
            'product_id' => $this->shampoo->id, 'kind' => 'label', 'title' => 'Front label', 'version' => 'v1',
            'status' => 'approved', 'approved_at' => now()->toDateString(), 'approved_by_name' => 'R. Mehta (ABC)', 'document' => $file,
        ])->assertRedirect();

        $v1 = ClientArtwork::query()->sole();
        $this->assertSame(ArtworkStatus::Approved, $v1->status);
        $this->assertCount(1, Storage::disk('local')->allFiles('clients/artworks'));

        $this->actingAs($this->factoryManager)->post(route('clients.artworks.store', $this->abc), [
            'product_id' => $this->shampoo->id, 'kind' => 'label', 'title' => 'Front label', 'version' => 'v2',
        ])->assertRedirect();

        $v2 = ClientArtwork::query()->where('version', 'v2')->sole();
        $this->assertSame(ArtworkStatus::Pending, $v2->status);

        $this->actingAs($this->factoryManager)->post(route('clients.artworks.status', [$this->abc, $v2]), [
            'status' => 'approved', 'approved_at' => now()->toDateString(),
        ])->assertRedirect();

        $this->assertSame(ArtworkStatus::Approved, $v2->fresh()->status);
        $this->assertSame(ArtworkStatus::Superseded, $v1->fresh()->status, 'Approving v2 supersedes v1.');

        $this->actingAs($this->factoryManager)
            ->get(route('clients.artworks.document', [$this->abc, $v1]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        // What ABC wants checked on their shampoo.
        $this->actingAs($this->factoryManager)->put(route('clients.qc-specs.upsert', [$this->abc, $this->shampoo]), [
            'parameters' => [
                ['name' => 'pH', 'min' => '5.5', 'max' => '6.0'],
                ['name' => 'Viscosity', 'min' => '4000', 'max' => '6000', 'unit' => 'cps'],
            ],
        ])->assertRedirect();

        $spec = ClientQcSpec::query()->sole();
        $this->assertCount(2, $spec->parameters);

        // A batch of theirs at the checkpoint shows the spec.
        $lot = InventoryLot::factory()->forItem($this->shampoo)->create(['qc_status' => LotQcStatus::Pending, 'owner_client_id' => $this->abc->id, 'batch_number' => 'TP-001-260913-001']);
        $inspection = QcInspection::create([
            'number' => 'QC-2609-00001', 'lot_id' => $lot->id, 'item_id' => $this->shampoo->id, 'quantity' => '100',
            'status' => LotQcStatus::Pending, 'destination_warehouse_id' => $this->fgStore->id, 'created_by' => $this->factoryManager->id,
        ]);

        $this->actingAs($this->factoryManager)
            ->get(route('qc.show', $inspection))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('owner.name', 'ABC Wellness Pvt Ltd')
                ->has('clientSpec.parameters', 2)
                ->where('clientSpec.parameters.0.name', 'pH')
            );

        $this->actingAs($this->factoryManager)
            ->get(route('clients.show', $this->abc))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('artworks', 2)->has('qcSpecs', 1));
    }

    #[Test]
    public function a_client_with_history_cannot_be_removed_but_can_be_made_inactive(): void
    {
        $this->plan();

        // The plant head may not remove clients at all; the owner may, but not one with history.
        $this->actingAs($this->factoryManager)->delete(route('clients.destroy', $this->abc))->assertForbidden();
        $this->actingAs($this->owner)->delete(route('clients.destroy', $this->abc))->assertRedirect();
        $this->assertNotNull(Client::query()->find($this->abc->id));

        $this->actingAs($this->factoryManager)->put(route('clients.update', $this->abc), ['name' => $this->abc->name, 'is_active' => false])->assertRedirect();
        $this->assertFalse($this->abc->fresh()->is_active);

        try {
            $this->plan();
            $this->fail('An inactive client cannot be planned for.');
        } catch (PlanningException $e) {
            $this->assertStringContainsString('inactive', $e->getMessage());
        }

        $this->actingAs($this->owner)->delete(route('clients.destroy', $this->xyz))->assertRedirect(route('clients.index'));
        $this->assertNull(Client::query()->find($this->xyz->id));
    }
}
