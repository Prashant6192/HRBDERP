<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Formulation\Models\FormulaVersion;
use App\Domain\Intelligence\DTOs\ItemOutlook;
use App\Domain\Intelligence\Services\ExpiryRiskService;
use App\Domain\Intelligence\Services\SlowMovingStockService;
use App\Domain\Intelligence\Services\StockOutlookService;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Manufacturing\Models\ManufacturingOrderLine;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Planning\Models\MaterialRequest;
use App\Domain\Planning\Models\MaterialRequestLine;
use App\Domain\Planning\Models\ProductionPlan;
use App\Domain\Planning\Models\ProductionPlanLine;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Procurement\Models\GoodsReceiptLine;
use App\Domain\Procurement\Models\Vendor;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use App\Support\Math\Decimal;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StockIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private Warehouse $store;

    private Warehouse $quarantine;

    private Uom $kg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        CarbonImmutable::setTestNow('2026-09-14 09:00:00');

        $this->facility = Facility::factory()->manufacturing()->withStores([WarehouseType::RawMaterial, WarehouseType::Packaging, WarehouseType::Quarantine])->create();
        $this->store = $this->facility->stores()->where('type', WarehouseType::RawMaterial->value)->firstOrFail();
        $this->quarantine = $this->facility->stores()->where('is_quarantine', true)->firstOrFail();
        $this->kg = Uom::query()->where('code', 'KG')->firstOrFail();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * The Surfactant A example from the brief: 42 on hand, 18 reserved,
     * 24 usable, 67 needed, 5-day lead time, production in 7 days.
     */
    #[Test]
    public function the_outlook_reads_the_surfactant_story_from_the_ledger_and_the_plans(): void
    {
        $item = RawMaterial::factory()->create(['name' => 'Surfactant A', 'code' => 'RM-SURF-A', 'stock_uom_id' => $this->kg->id, 'lead_time_days' => 5, 'minimum_stock' => '0', 'reorder_level' => '30', 'min_order_quantity' => null, 'order_multiple' => '25']);
        $lot = InventoryLot::factory()->forItem($item)->create();
        app(InventoryLedgerService::class)->receive($item, $this->store, '42', $lot);

        // 18 KG reserved for a running batch...
        $order = $this->order($item, planned: '18', reserved: '18');
        // ...and a checked plan a week away wanting 67 KG more.
        $this->plan($item, '67', CarbonImmutable::now()->addDays(7)->toDateString());

        $vendor = Vendor::factory()->create(['name' => 'Vendor ABC']);
        $this->delivery($item, $vendor, '25', '118.00', CarbonImmutable::now()->subDays(20));

        $this->store->balances()->where('item_id', $item->id)->update(['reserved' => '18']);

        $outlook = app(StockOutlookService::class)->forItem($item->fresh());

        $this->assertSame('42', Decimal::strip($outlook->onHand));
        $this->assertSame('18', Decimal::strip($outlook->reserved));
        $this->assertSame('24', Decimal::strip($outlook->usable));
        $this->assertSame('67', Decimal::strip($outlook->upcomingRequirement), 'The running batch is fully reserved; only the plan is still to come.');
        $this->assertSame('43', Decimal::strip($outlook->shortfall));
        $this->assertSame('50', (string) $outlook->recommendedQuantity, '43 KG rounded up to whole 25 KG bags.');
        $this->assertSame(5, $outlook->leadTimeDays);
        $this->assertSame('item', $outlook->leadTimeSource);
        $this->assertSame('2026-09-21', $outlook->neededBy?->toDateString());
        $this->assertSame('2026-09-16', $outlook->orderBy?->toDateString(), 'Needed on the 21st, five days to deliver: order by the 16th.');
        $this->assertSame(ItemOutlook::ORDER_SOON, $outlook->status);
        $this->assertSame('Vendor ABC', $outlook->vendor['name']);
        $this->assertSame('118.0000', $outlook->vendor['last_price']);

        $text = implode(' ', $outlook->sentences());
        $this->assertStringContainsString('42 KG on hand', $text);
        $this->assertStringContainsString('18 KG already reserved', $text);
        $this->assertStringContainsString('24 KG usable', $text);
        $this->assertStringContainsString('Upcoming production requires 67 KG', $text);
        $this->assertStringContainsString('Shortfall: 43 KG', $text);
        $this->assertStringContainsString('Supplier lead time: 5 days', $text);
        $this->assertStringContainsString('order 50 KG by 16 Sep from Vendor ABC (last price ₹118.00 per KG)', $text);

        $this->assertNotNull($order);
    }

    #[Test]
    public function consumption_rate_and_material_requests_shape_the_advice(): void
    {
        $item = RawMaterial::factory()->create(['name' => 'Fragrance X', 'stock_uom_id' => $this->kg->id, 'lead_time_days' => 10, 'minimum_stock' => '5', 'reorder_level' => '10', 'min_order_quantity' => '20']);
        $lot = InventoryLot::factory()->forItem($item)->create();
        $ledger = app(InventoryLedgerService::class);
        $ledger->receive($item, $this->store, '100', $lot, at: CarbonImmutable::now()->subDays(60));

        // 2 KG a day for the last 30 days, leaving 40 KG.
        for ($d = 30; $d >= 1; $d--) {
            $ledger->issue($item, $this->store, '2', $lot, InventoryTransactionType::ProductionConsumption, at: CarbonImmutable::now()->subDays($d));
        }

        $outlook = app(StockOutlookService::class)->forItem($item->fresh());

        $this->assertSame('40', Decimal::strip($outlook->usable));
        $this->assertSame('2', Decimal::strip($outlook->dailyRate), 'Sixty units over thirty days.');
        $this->assertSame(20, $outlook->daysOfCover);
        $this->assertSame('2026-10-04', $outlook->runsOutAt?->toDateString());
        // 30 days of demand (60) + safety (5) − usable (40) = 25 short; MOQ 20 does not bind.
        $this->assertSame('25', Decimal::strip($outlook->shortfall));
        $this->assertSame('25', (string) $outlook->recommendedQuantity);
        $this->assertSame('2026-09-24', $outlook->orderBy?->toDateString(), 'Runs out on 4 Oct, ten days to deliver.');
        $this->assertSame(ItemOutlook::WATCH, $outlook->status);

        // Purchase has already asked for 30 KG: nothing more to order.
        $this->request($item, '30', received: '0');
        $again = app(StockOutlookService::class)->forItem($item->fresh());
        $this->assertSame('30', Decimal::strip($again->onOrder));
        $this->assertSame('0', (string) $again->recommendedQuantity);
        $this->assertSame(ItemOutlook::OK, $again->status);
    }

    #[Test]
    public function stock_that_is_expired_in_quarantine_or_awaiting_qc_is_not_usable(): void
    {
        $item = RawMaterial::factory()->create(['stock_uom_id' => $this->kg->id, 'minimum_stock' => '0', 'reorder_level' => '10']);
        $ledger = app(InventoryLedgerService::class);
        $ledger->receive($item, $this->store, '10', InventoryLot::factory()->forItem($item)->create());
        $ledger->receive($item, $this->store, '7', InventoryLot::factory()->forItem($item)->expired()->create());
        $ledger->receive($item, $this->store, '5', InventoryLot::factory()->forItem($item)->pendingQc()->create());
        $ledger->receive($item, $this->quarantine, '9', InventoryLot::factory()->forItem($item)->create());

        $outlook = app(StockOutlookService::class)->forItem($item->fresh());

        $this->assertSame('31', Decimal::strip($outlook->onHand));
        $this->assertSame('9', Decimal::strip($outlook->inQuarantine));
        $this->assertSame('10', Decimal::strip($outlook->usable));
    }

    #[Test]
    public function the_reorder_advice_page_lists_the_urgent_first_and_the_dashboard_counts_them(): void
    {
        $urgent = RawMaterial::factory()->create(['name' => 'Urgent Thing', 'stock_uom_id' => $this->kg->id, 'lead_time_days' => 5, 'minimum_stock' => '0', 'reorder_level' => '10']);
        $this->plan($urgent, '50', CarbonImmutable::now()->addDay()->toDateString());

        $later = RawMaterial::factory()->create(['name' => 'Later Thing', 'stock_uom_id' => $this->kg->id, 'lead_time_days' => 2, 'minimum_stock' => '0', 'reorder_level' => '10']);
        $this->plan($later, '50', CarbonImmutable::now()->addDays(20)->toDateString());

        $fine = RawMaterial::factory()->create(['name' => 'Fine Thing', 'stock_uom_id' => $this->kg->id, 'minimum_stock' => '0', 'reorder_level' => '10']);
        app(InventoryLedgerService::class)->receive($fine, $this->store, '500', InventoryLot::factory()->forItem($fine)->create());

        $user = User::factory()->create();
        $user->assignRole(RoleName::PurchaseManager->value);

        $this->actingAs($user)->get(route('purchase.reorder-advice'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('purchase/reorder-advice')
                ->has('rows', 2)
                ->where('rows.0.name', 'Urgent Thing')
                ->where('rows.0.status', 'order_today')
                ->where('rows.0.recommended_quantity', '50')
                ->where('rows.1.name', 'Later Thing')
                ->where('rows.1.status', 'watch')
                ->where('counts.order_today', 1)
                ->where('counts.watch', 1)
            );

        $this->actingAs($user)->get(route('purchase.reorder-advice', ['all' => 1]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 3)->where('rows.2.name', 'Fine Thing')->where('rows.2.status', 'ok'));

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('kpis', fn ($kpis) => collect($kpis)->firstWhere('key', 'reorder')['value'] === 1)
            );

        // A QC executive has no business with purchase advice.
        $hand = User::factory()->create();
        $hand->assignRole(RoleName::QcExecutive->value);
        $this->actingAs($hand)->get(route('purchase.reorder-advice'))->assertForbidden();
    }

    #[Test]
    public function slow_moving_stock_is_bucketed_and_valued(): void
    {
        $item = RawMaterial::factory()->create(['name' => 'Gold Dust', 'stock_uom_id' => $this->kg->id, 'standard_cost' => '900']);
        $ledger = app(InventoryLedgerService::class);

        $old = InventoryLot::factory()->forItem($item)->create(['unit_cost' => '1000', 'received_at' => CarbonImmutable::now()->subDays(200)->toDateString()]);
        $ledger->receive($item, $this->store, '10', $old, at: CarbonImmutable::now()->subDays(200));

        $touched = InventoryLot::factory()->forItem($item)->create(['unit_cost' => null, 'received_at' => CarbonImmutable::now()->subDays(200)->toDateString()]);
        $ledger->receive($item, $this->store, '10', $touched, at: CarbonImmutable::now()->subDays(200));
        $ledger->issue($item, $this->store, '2', $touched, InventoryTransactionType::ProductionConsumption, at: CarbonImmutable::now()->subDays(45));

        $fresh = InventoryLot::factory()->forItem($item)->create();
        $ledger->receive($item, $this->store, '10', $fresh);

        $report = app(SlowMovingStockService::class)->report(null, 30);

        $this->assertCount(2, $report['rows']);
        $this->assertSame($old->batch_number, $report['rows'][0]['batch_number']);
        $this->assertSame(200, $report['rows'][0]['idle_days']);
        $this->assertSame(180, $report['rows'][0]['bucket']);
        $this->assertSame('10000.00', $report['rows'][0]['value'], 'Valued at the lot cost.');
        $this->assertFalse($report['rows'][0]['ever_issued']);

        $this->assertSame($touched->batch_number, $report['rows'][1]['batch_number']);
        $this->assertSame(45, $report['rows'][1]['idle_days'], 'Idle since the last issue, not since arrival.');
        $this->assertSame(30, $report['rows'][1]['bucket']);
        $this->assertSame('7200.00', $report['rows'][1]['value'], '8 KG at the standard cost when the lot has none.');
        $this->assertSame('17200.00', $report['total_value']);

        $over180 = collect($report['buckets'])->firstWhere('days', 180);
        $this->assertSame(1, $over180['lots']);
        $this->assertSame('10000.00', $over180['value']);

        $user = User::factory()->create();
        $user->assignRole(RoleName::WarehouseManager->value);
        $this->actingAs($user)->get(route('stock.slow-moving', ['days' => 90]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('stock/slow-moving')->has('report.rows', 1)->where('filters.days', 90));
    }

    #[Test]
    public function expiry_risk_uses_the_rate_of_use_first_expiry_first(): void
    {
        $item = RawMaterial::factory()->create(['name' => 'Aloe Extract', 'stock_uom_id' => $this->kg->id]);
        $ledger = app(InventoryLedgerService::class);

        // 1 KG a day.
        $working = InventoryLot::factory()->forItem($item)->create(['expiry_at' => CarbonImmutable::now()->addYears(2)->toDateString()]);
        $ledger->receive($item, $this->store, '100', $working, at: CarbonImmutable::now()->subDays(40));
        for ($d = 30; $d >= 1; $d--) {
            $ledger->issue($item, $this->store, '1', $working, InventoryTransactionType::ProductionConsumption, at: CarbonImmutable::now()->subDays($d));
        }

        // Two lots inside the window: 20 KG expiring in 10 days, 50 KG in 40 days.
        $soon = InventoryLot::factory()->forItem($item)->create(['unit_cost' => '200', 'expiry_at' => CarbonImmutable::now()->addDays(10)->toDateString()]);
        $ledger->receive($item, $this->store, '20', $soon);
        $later = InventoryLot::factory()->forItem($item)->create(['unit_cost' => '200', 'expiry_at' => CarbonImmutable::now()->addDays(40)->toDateString()]);
        $ledger->receive($item, $this->store, '50', $later);

        $report = app(ExpiryRiskService::class)->report(null, 90);

        $this->assertSame(2, $report['lots']);
        $rows = collect($report['rows'])->keyBy('batch_number');

        // 10 days × 1 KG = 10 of 20 used: 50% coverage, 10 KG (₹2,000) at risk.
        $this->assertSame('10', $rows[$soon->batch_number]['expected_use']);
        $this->assertSame('50.0', $rows[$soon->batch_number]['coverage_percent']);
        $this->assertSame('2000.00', $rows[$soon->batch_number]['at_risk_value']);
        $this->assertSame('medium', $rows[$soon->batch_number]['level']);

        // 40 days × 1 KG = 40, less the 20 KG lot ahead of it = 20 of 50: 40%.
        $this->assertSame('20', $rows[$later->batch_number]['expected_use']);
        $this->assertSame('40.0', $rows[$later->batch_number]['coverage_percent']);
        $this->assertSame('6000.00', $rows[$later->batch_number]['at_risk_value']);
        $this->assertSame('high', $rows[$later->batch_number]['level']);
        $this->assertStringContainsString('will expire in 40 days; expected consumption before expiry is only 40.0%', $rows[$later->batch_number]['sentence']);
        $this->assertStringContainsString('₹6,000 of inventory at risk', $rows[$later->batch_number]['sentence']);

        $this->assertSame('8000.00', $report['at_risk_value']);
        $this->assertSame($later->batch_number, $report['rows'][0]['batch_number'], 'Highest risk first.');

        $user = User::factory()->create();
        $user->assignRole(RoleName::WarehouseManager->value);
        $this->actingAs($user)->get(route('stock.expiry-risk', ['days' => 30]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('stock/expiry-risk')->has('report.rows', 1)->where('report.window_days', 30));
    }

    #[Test]
    public function a_material_page_carries_its_outlook(): void
    {
        $item = RawMaterial::factory()->create(['name' => 'Outlook Thing', 'stock_uom_id' => $this->kg->id, 'minimum_stock' => '0', 'reorder_level' => '10']);
        $user = User::factory()->create();
        $user->assignRole(RoleName::WarehouseManager->value);

        $this->actingAs($user)->get(route('raw-materials.show', $item))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('outlook.code', $item->code)
                ->where('outlook.status', 'ok')
                ->has('outlook.sentences')
            );
    }

    private function order(RawMaterial $item, string $planned, string $reserved = '0', string $consumed = '0', string $status = 'in_progress'): ManufacturingOrder
    {
        [$formula, $version] = $this->formula();

        $order = ManufacturingOrder::query()->create([
            'number' => 'MO-'.fake()->unique()->numerify('####'),
            'facility_id' => $this->facility->id,
            'formula_id' => $formula->id,
            'formula_version_id' => $version->id,
            'planned_quantity' => '100',
            'planned_uom_id' => $this->kg->id,
            'status' => $status,
        ]);

        ManufacturingOrderLine::query()->create([
            'manufacturing_order_id' => $order->id,
            'line_no' => 1,
            'store_kind' => 'raw_material',
            'item_id' => $item->id,
            'uom_id' => $this->kg->id,
            'percentage' => '18',
            'planned_quantity' => $planned,
            'reserved_quantity' => $reserved,
            'consumed_quantity' => $consumed,
        ]);

        return $order;
    }

    private function plan(RawMaterial $item, string $required, ?string $start, string $status = 'checked'): ProductionPlan
    {
        [$formula, $version] = $this->formula();

        $plan = ProductionPlan::query()->create([
            'number' => 'PP-'.fake()->unique()->numerify('####'),
            'facility_id' => $this->facility->id,
            'formula_id' => $formula->id,
            'formula_version_id' => $version->id,
            'planned_quantity' => '100',
            'planned_uom_id' => $this->kg->id,
            'status' => $status,
            'planned_start_date' => $start,
        ]);

        ProductionPlanLine::query()->create([
            'production_plan_id' => $plan->id,
            'line_no' => 1,
            'store_kind' => 'raw_material',
            'source' => 'company',
            'item_id' => $item->id,
            'uom_id' => $this->kg->id,
            'percentage' => '67',
            'required_quantity' => $required,
            'available_quantity' => '0',
            'shortage_quantity' => $required,
            'level_now' => 'low',
            'level_after' => 'low',
        ]);

        return $plan;
    }

    private function request(RawMaterial $item, string $toOrder, string $received): MaterialRequest
    {
        $plan = $this->plan($item, $toOrder, null, 'requested');

        $request = MaterialRequest::query()->create([
            'number' => 'PMR-'.fake()->unique()->numerify('####'),
            'production_plan_id' => $plan->id,
            'store_kind' => 'raw_material',
            'warehouse_id' => $this->store->id,
            'status' => 'open',
            'requested_at' => now(),
        ]);

        MaterialRequestLine::query()->create([
            'material_request_id' => $request->id,
            'line_no' => 1,
            'source' => 'company',
            'item_id' => $item->id,
            'uom_id' => $this->kg->id,
            'required_quantity' => $toOrder,
            'quantity_to_order' => $toOrder,
            'received_quantity' => $received,
            'alert_level' => 'low',
        ]);

        // The requested plan's own line would otherwise count as demand twice.
        ProductionPlanLine::query()->where('production_plan_id', $plan->id)->update(['required_quantity' => '0']);

        return $request;
    }

    private function delivery(RawMaterial $item, Vendor $vendor, string $quantity, string $price, CarbonImmutable $postedAt): GoodsReceipt
    {
        $receipt = GoodsReceipt::query()->create([
            'number' => 'GRN-'.fake()->unique()->numerify('####'),
            'vendor_id' => $vendor->id,
            'warehouse_id' => $this->quarantine->id,
            'received_at' => $postedAt->toDateString(),
            'status' => 'received',
            'posted_at' => $postedAt,
        ]);

        GoodsReceiptLine::query()->create([
            'goods_receipt_id' => $receipt->id,
            'item_id' => $item->id,
            'quantity' => $quantity,
            'uom_id' => $this->kg->id,
            'stock_quantity' => $quantity,
            'unit_price' => $price,
        ]);

        return $receipt;
    }

    /**
     * @return array{0: Formula, 1: FormulaVersion}
     */
    private function formula(): array
    {
        $formula = Formula::factory()->create();
        $version = FormulaVersion::factory()->active()->create(['formula_id' => $formula->id]);

        return [$formula, $version];
    }
}
