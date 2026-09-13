<?php

declare(strict_types=1);

namespace Tests\Feature\ShopFloor;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Contract\Models\Client;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Formulation\Services\FormulaService;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\FloorPhoto;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\InventoryTransaction;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\Inventory\Services\StockCountService;
use App\Domain\Manufacturing\Enums\ManufacturingOrderStatus;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Manufacturing\Models\ManufacturingOrderScan;
use App\Domain\Manufacturing\Services\IssueVerificationService;
use App\Domain\Manufacturing\Services\ManufacturingOrderService;
use App\Domain\MasterData\Models\PackagingMaterial;
use App\Domain\MasterData\Models\Product;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Planning\Services\ProductionPlanService;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use App\Support\Scanning\ScanCode;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShopFloorTest extends TestCase
{
    use RefreshDatabase;

    private ManufacturingOrderService $orders;

    private InventoryLedgerService $ledger;

    private User $productionManager;

    private User $factoryManager;

    private Facility $facility;

    private Warehouse $rmStore;

    private Warehouse $pmStore;

    private RawMaterial $surfactant;

    private RawMaterial $water;

    private PackagingMaterial $bottle;

    private Formula $formula;

    private Uom $kg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->orders = app(ManufacturingOrderService::class);
        $this->ledger = app(InventoryLedgerService::class);

        $this->productionManager = $this->user(RoleName::ProductionManager);
        $this->factoryManager = $this->user(RoleName::FactoryManager);

        $this->facility = Facility::factory()->manufacturing()->create(['code' => 'FAC-FLR-001', 'name' => 'Floor Plant']);
        $this->rmStore = Warehouse::factory()->atFacility($this->facility)->create(['code' => 'WH-RM', 'type' => WarehouseType::RawMaterial]);
        $this->pmStore = Warehouse::factory()->atFacility($this->facility)->create(['code' => 'WH-PM', 'type' => WarehouseType::Packaging]);
        Warehouse::factory()->atFacility($this->facility)->create(['code' => 'WH-FG', 'type' => WarehouseType::FinishedGoods]);
        Warehouse::factory()->atFacility($this->facility)->quarantine()->create(['code' => 'WH-QA']);

        $this->kg = Uom::where('code', 'KG')->firstOrFail();
        $ml = Uom::where('code', 'ML')->firstOrFail();
        $pcs = Uom::where('code', 'PCS')->firstOrFail();

        $this->surfactant = RawMaterial::factory()->create(['name' => 'Surfactant A', 'stock_uom_id' => $this->kg->id, 'reorder_level' => null, 'minimum_stock' => null, 'standard_cost' => '100']);
        $this->water = RawMaterial::factory()->create(['name' => 'Purified Water', 'stock_uom_id' => $this->kg->id, 'density_g_per_ml' => '1', 'reorder_level' => null, 'minimum_stock' => null, 'standard_cost' => '1']);
        $this->bottle = PackagingMaterial::factory()->create(['name' => 'Bottle 100 ml', 'stock_uom_id' => $pcs->id, 'reorder_level' => null, 'minimum_stock' => null]);

        $product = Product::factory()->create(['name' => 'Floor Face Wash 100 ml', 'net_content' => '100', 'net_content_uom_id' => $ml->id, 'stock_uom_id' => $pcs->id, 'requires_qc' => true, 'shelf_life_days' => 730]);
        $product->packagingLines()->create(['packaging_material_id' => $this->bottle->id, 'quantity_per_unit' => '1']);

        $formulas = app(FormulaService::class);
        $this->formula = $formulas->create([
            'name' => 'Floor Face Wash', 'product_id' => $product->id, 'batch_uom_id' => Uom::where('code', 'G')->value('id'),
        ], [
            ['item_id' => $this->surfactant->id, 'percentage' => '15'],
            ['item_id' => $this->water->id, 'is_qs' => true],
        ], $this->factoryManager->id);
        $formulas->activate($this->formula->versions()->first(), $this->factoryManager->id);
        $this->formula->refresh();
    }

    private function user(RoleName $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role->value);

        return $user;
    }

    /** Every batch carries a sticker, so every receipt here has a lot. */
    private function lot(RawMaterial|PackagingMaterial $item, string $batch, array $attributes = []): InventoryLot
    {
        return InventoryLot::factory()->forItem($item)->create(['batch_number' => $batch, 'qc_status' => LotQcStatus::Approved, 'expiry_at' => now()->addMonths(6), ...$attributes]);
    }

    private function stockTheStores(): void
    {
        $this->ledger->receive($this->surfactant, $this->rmStore, '20', $this->lot($this->surfactant, 'SURF-001'), unitCost: '100', userId: $this->factoryManager->id);
        $this->ledger->receive($this->water, $this->rmStore, '200', $this->lot($this->water, 'WATER-001'), unitCost: '1', userId: $this->factoryManager->id);
        $this->ledger->receive($this->bottle, $this->pmStore, '2000', $this->lot($this->bottle, 'BTL-001'), userId: $this->factoryManager->id);
    }

    private function approvedOrder(): ManufacturingOrder
    {
        $plan = app(ProductionPlanService::class)->create(['formula_id' => $this->formula->id, 'quantity' => '100', 'uom_id' => $this->kg->id], $this->productionManager->id);

        return $this->orders->approve($this->orders->createFromPlan($plan, $this->productionManager->id), $this->factoryManager->id);
    }

    #[Test]
    public function scan_codes_round_trip_through_urls(): void
    {
        $this->assertSame(['type' => 'LOT', 'value' => 'SURF-001'], ScanCode::parse(ScanCode::url('LOT:SURF-001')));
        $this->assertSame(['type' => 'MO', 'value' => 'MO-2609-00001'], ScanCode::parse('MO:MO-2609-00001'));
        $this->assertSame(['type' => 'LOC', 'value' => 'WH-RM/A1'], ScanCode::parse('LOC:WH-RM/A1'));
        $this->assertSame(['type' => null, 'value' => 'SURF-001'], ScanCode::parse('  SURF-001 '));
    }

    #[Test]
    public function the_right_batch_verifies_and_every_wrong_one_is_blocked_with_a_reason(): void
    {
        $this->stockTheStores();
        $order = $this->approvedOrder();
        $verification = app(IssueVerificationService::class);

        // The reserved, approved, in-date batch of an ingredient on the recipe.
        $ok = $verification->check($order, 'LOT:SURF-001');
        $this->assertSame('ok', $ok['verdict'], implode(' ', $ok['reasons']));

        // Wrong ingredient: a material that is not on this batch at all.
        $fragrance = RawMaterial::factory()->create(['name' => 'Fragrance Rose', 'stock_uom_id' => $this->kg->id]);
        $this->ledger->receive($fragrance, $this->rmStore, '5', $this->lot($fragrance, 'FRAG-001'));
        $wrong = $verification->check($order, 'LOT:FRAG-001');
        $this->assertSame('blocked', $wrong['verdict']);
        $this->assertStringContainsString('Fragrance Rose is not on', $wrong['reasons'][0]);

        // Wrong batch: same material, but not the one the store held for this order.
        $this->ledger->receive($this->surfactant, $this->rmStore, '20', $this->lot($this->surfactant, 'SURF-002'));
        $other = $verification->check($order, 'LOT:SURF-002');
        $this->assertSame('blocked', $other['verdict']);
        $this->assertStringContainsString('not the one held for this order', $other['reasons'][0]);
        $this->assertStringContainsString('SURF-001', $other['reasons'][0]);

        // Expired stock.
        $expiredLot = $this->lot($this->surfactant, 'SURF-EXP', ['expiry_at' => now()->subDay()]);
        $this->ledger->receive($this->surfactant, $this->rmStore, '5', $expiredLot);
        $expired = $verification->check($order, 'LOT:SURF-EXP');
        $this->assertSame('blocked', $expired['verdict']);
        $this->assertTrue(collect($expired['reasons'])->contains(fn (string $r) => str_contains($r, 'expired on')));

        // Not released by QC.
        $held = $this->lot($this->surfactant, 'SURF-QC', ['qc_status' => LotQcStatus::Rejected]);
        $this->ledger->receive($this->surfactant, $this->rmStore, '5', $held);
        $rejected = $verification->check($order, 'LOT:SURF-QC');
        $this->assertSame('blocked', $rejected['verdict']);
        $this->assertTrue(collect($rejected['reasons'])->contains(fn (string $r) => str_contains($r, 'has not been released by QC')));

        // Another client's material.
        $client = Client::factory()->create(['name' => 'Rozz Beauty']);
        $foreign = $this->lot($this->surfactant, 'SURF-ROZZ', ['owner_client_id' => $client->id]);
        $this->ledger->receive($this->surfactant, $this->rmStore, '5', $foreign);
        $owner = $verification->check($order, 'LOT:SURF-ROZZ');
        $this->assertSame('blocked', $owner['verdict']);
        $this->assertTrue(collect($owner['reasons'])->contains(fn (string $r) => str_contains($r, 'belongs to Rozz Beauty')));

        // A material code instead of a batch sticker, and a code nobody knows.
        $this->assertStringContainsString('Scan the batch sticker', $verification->check($order, ScanCode::item($this->surfactant->code))['reasons'][0]);
        $this->assertStringContainsString('No batch matches', $verification->check($order, 'LOT:NOPE')['reasons'][0]);
    }

    #[Test]
    public function scans_are_recorded_against_the_order_and_a_batch_cannot_start_until_every_raw_material_is_verified(): void
    {
        config(['erp.shop_floor.require_scan_before_start' => true]);
        $this->stockTheStores();
        $order = $this->approvedOrder();

        // Nothing scanned: the start is refused and says what is missing.
        $this->actingAs($this->productionManager)->post(route('manufacturing.start', $order))->assertRedirect();
        $this->assertSame(ManufacturingOrderStatus::Approved, $order->fresh()->status);

        // A blocked scan is kept as evidence; a good one verifies the line.
        $this->actingAs($this->productionManager)->postJson(route('manufacturing.scan', $order), ['code' => 'LOT:NOPE'])
            ->assertOk()->assertJsonPath('verdict', 'blocked');
        $this->actingAs($this->productionManager)->postJson(route('manufacturing.scan', $order), ['code' => ScanCode::url('LOT:SURF-001')])
            ->assertOk()->assertJsonPath('verdict', 'ok')->assertJsonPath('lot.batch_number', 'SURF-001');

        $this->assertSame(2, ManufacturingOrderScan::query()->where('manufacturing_order_id', $order->id)->count());
        $this->assertSame(1, ManufacturingOrderScan::query()->where('verdict', 'blocked')->count());

        $this->actingAs($this->productionManager)->get(route('manufacturing.show', $order))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('verification.verified', 1)
                ->where('verification.required', 2)
                ->where('verification.complete', false)
                ->where('scanCode', ScanCode::order($order->number)));

        // Still one raw material short: the start is still refused.
        $this->actingAs($this->productionManager)->post(route('manufacturing.start', $order))->assertRedirect();
        $this->assertSame(ManufacturingOrderStatus::Approved, $order->fresh()->status);

        $this->actingAs($this->productionManager)->post(route('manufacturing.scan', $order), ['code' => 'LOT:WATER-001'])->assertRedirect();

        $this->actingAs($this->productionManager)->get(route('floor.issue', $order))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('floor/issue')->where('verification.complete', true)->where('packaging.required', 1));

        $this->actingAs($this->productionManager)->post(route('manufacturing.start', $order))->assertRedirect();
        $this->assertSame(ManufacturingOrderStatus::InProgress, $order->fresh()->status);
    }

    #[Test]
    public function the_scan_gate_is_off_unless_the_factory_turns_it_on(): void
    {
        config(['erp.shop_floor.require_scan_before_start' => false]);
        $this->stockTheStores();
        $order = $this->approvedOrder();

        $this->actingAs($this->productionManager)->post(route('manufacturing.start', $order))->assertRedirect();
        $this->assertSame(ManufacturingOrderStatus::InProgress, $order->fresh()->status);
    }

    #[Test]
    public function a_stock_count_posts_the_differences_once_someone_else_approves_it(): void
    {
        $this->stockTheStores();
        $counter = $this->user(RoleName::StoreExecutive);
        $checker = $this->user(RoleName::WarehouseManager);
        $balances = app(StockBalanceService::class);

        // The store executive starts the count; the sheet is a snapshot of the system.
        $this->actingAs($counter)->post(route('counts.store'), ['warehouse_id' => $this->rmStore->id, 'notes' => 'Month end'])->assertRedirect();
        $count = StockCount::query()->firstOrFail();
        $this->assertMatchesRegularExpression('/^SC-\d{4}-0001$/', $count->number);
        $this->assertSame(2, $count->lines()->count());

        // Only one count at a time per store.
        $this->actingAs($counter)->post(route('counts.store'), ['warehouse_id' => $this->rmStore->id])->assertSessionHasErrors('warehouse_id');

        // Counting by scan: the surfactant is 2 kg short, the water matches.
        $this->actingAs($counter)->post(route('counts.line', $count), ['code' => ScanCode::url('LOT:SURF-001'), 'counted' => '18', 'note' => 'One drum leaked'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($counter)->post(route('counts.line', $count), ['code' => 'LOT:WATER-001', 'counted' => '200'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($counter)->post(route('counts.line', $count), ['code' => 'LOT:NOPE', 'counted' => '1'])->assertSessionHasErrors('code');

        // A batch on the shelf that the system does not know about is added with system 0.
        $stray = $this->lot($this->surfactant, 'SURF-STRAY');
        $this->actingAs($counter)->post(route('counts.line', $count), ['code' => 'LOT:SURF-STRAY', 'counted' => '3'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(3, $count->lines()->count());

        $this->actingAs($counter)->get(route('counts.show', $count))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('accuracy.counted', 3)
                ->where('accuracy.accurate', 1)
                ->where('accuracy.accuracy_percent', '33.3')
                ->where('accuracy.variance_value', '600.00')
                ->where('can.submit', true)
                ->where('can.approve', false));

        $this->actingAs($counter)->post(route('counts.submit', $count))->assertRedirect();
        $this->assertSame('submitted', $count->fresh()->status->value);

        // Maker-checker: the counter cannot approve their own count; nothing is posted.
        $this->actingAs($counter)->post(route('counts.approve', $count))->assertForbidden();
        try {
            app(StockCountService::class)->approve($count, $counter->id);
            $this->fail('The counter approved their own count.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Maker and checker must differ', $e->getMessage());
        }
        $this->assertSame(0, InventoryTransaction::query()->whereIn('type', [InventoryTransactionType::StockAdjustmentIn->value, InventoryTransactionType::StockAdjustmentOut->value])->count());

        // The warehouse manager approves: the ledger now matches the shelf.
        $this->actingAs($checker)->post(route('counts.approve', $count))->assertRedirect();
        $count->refresh();
        $this->assertSame('approved', $count->status->value);
        $this->assertSame($checker->id, $count->approved_by);
        $this->assertTrue($balances->onHand($this->surfactant, $this->rmStore)->isEqualTo('21'), 'Shelf: 18 + 3');
        $this->assertTrue($balances->onHand($this->water, $this->rmStore)->isEqualTo('200'));

        $adjustments = InventoryTransaction::query()->where('reference_type', $count->getMorphClass())->where('reference_id', $count->id)->get();
        $this->assertCount(2, $adjustments, 'One out for the short drum, one in for the stray batch; nothing for the line that matched');
        $this->assertSame(1, $adjustments->where('type', InventoryTransactionType::StockAdjustmentOut)->count());
        $this->assertSame(1, $adjustments->where('type', InventoryTransactionType::StockAdjustmentIn)->count());
        $this->assertSame('3.000000', StockBalance::query()->where('lot_id', $stray->id)->value('on_hand'));

        $this->actingAs($checker)->get(route('counts.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('counts', 1)->where('counts.0.accuracy', '33.3'));
    }

    #[Test]
    public function the_floor_resolves_any_code_into_the_thing_it_names_and_the_actions_allowed(): void
    {
        $this->stockTheStores();
        $order = $this->approvedOrder();
        $location = $this->rmStore->locations()->create(['code' => 'A1', 'name' => 'Rack A1', 'type' => 'rack', 'is_active' => true]);

        $this->actingAs($this->productionManager)->postJson(route('floor.lookup'), ['code' => ScanCode::url('LOT:SURF-001')])
            ->assertOk()->assertJsonPath('kind', 'lot')->assertJsonPath('lot.batch_number', 'SURF-001');

        $this->actingAs($this->productionManager)->postJson(route('floor.lookup'), ['code' => 'MO:'.$order->number])
            ->assertOk()->assertJsonPath('kind', 'order')->assertJsonPath('order.id', $order->id);

        $this->actingAs($this->productionManager)->postJson(route('floor.lookup'), ['code' => 'ITEM:'.$this->surfactant->code])
            ->assertOk()->assertJsonPath('kind', 'item')->assertJsonPath('item.on_hand', '20');

        $this->actingAs($this->productionManager)->postJson(route('floor.lookup'), ['code' => 'LOC:WH-RM/A1'])
            ->assertOk()->assertJsonPath('kind', 'location')->assertJsonPath('location.id', $location->id);

        $this->actingAs($this->productionManager)->postJson(route('floor.lookup'), ['code' => 'LOC:WH-RM/'])
            ->assertOk()->assertJsonPath('kind', 'store');

        $this->actingAs($this->productionManager)->postJson(route('floor.lookup'), ['code' => 'whatever'])
            ->assertOk()->assertJsonPath('kind', 'unknown');

        // The floor pages render with the running work and what this person may do.
        $this->actingAs($this->productionManager)->get(route('floor.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('floor/index')->has('running', 1)->where('can.issue', true)->where('can.count', false));
        $this->actingAs($this->productionManager)->get(route('floor.scan', ['c' => 'LOT:SURF-001']))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('floor/scan')->where('result.kind', 'lot'));

        $this->orders->start($order, $this->productionManager->id);
        $this->actingAs($this->productionManager)->get(route('floor.production'))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('floor/production')->has('orders', 1)->where('can.record', true));
    }

    #[Test]
    public function the_batch_card_and_store_labels_print_with_qr_codes(): void
    {
        $this->stockTheStores();
        $order = $this->approvedOrder();
        $this->rmStore->locations()->create(['code' => 'A1', 'name' => 'Rack A1', 'type' => 'rack', 'is_active' => true]);

        $this->actingAs($this->productionManager)->get(route('manufacturing.card', $order))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('manufacturing/card')
                ->where('code', ScanCode::order($order->number))
                ->where('qr', fn (string $qr) => str_starts_with($qr, 'data:image/png;base64,'))
                ->has('order.lines', 3));

        $this->actingAs($this->factoryManager)->get(route('stores.labels', $this->rmStore))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('stores/labels')
                ->has('labels', 1)
                ->where('labels.0.scan_code', 'LOC:WH-RM/A1')
                ->where('store.scan_code', 'LOC:WH-RM/'));
    }

    #[Test]
    public function a_photo_taken_on_the_floor_is_kept_against_the_batch(): void
    {
        Storage::fake('local');
        $this->stockTheStores();
        $lot = InventoryLot::query()->where('batch_number', 'SURF-001')->firstOrFail();

        $this->actingAs($this->factoryManager)->post(route('floor.photo'), [
            'subject_type' => 'lot', 'subject_id' => $lot->id, 'note' => 'Drum dented on arrival',
            'photo' => UploadedFile::fake()->image('drum.jpg', 640, 480),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $photo = FloorPhoto::query()->firstOrFail();
        $this->assertSame($lot->getMorphClass(), $photo->subject_type);
        $this->assertSame($lot->id, $photo->subject_id);
        $this->assertSame('Drum dented on arrival', $photo->note);
        $this->assertSame($this->factoryManager->id, $photo->taken_by);
        Storage::disk('local')->assertExists($photo->path);
    }
}
