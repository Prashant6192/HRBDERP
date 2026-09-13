<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Contract\Models\Client;
use App\Domain\Contract\Services\ClientProfitabilityService;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Formulation\Models\FormulaIngredient;
use App\Domain\Formulation\Models\FormulaVersion;
use App\Domain\Intelligence\Services\CapacityService;
use App\Domain\Intelligence\Services\ProductionAnalyticsService;
use App\Domain\Intelligence\Services\WhatIfService;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\InventoryTransaction;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Manufacturing\Models\ManufacturingOrderLine;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductionAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private Warehouse $store;

    private Uom $kg;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        CarbonImmutable::setTestNow('2026-09-14 09:00:00'); // a Monday

        $this->facility = Facility::factory()->manufacturing()->withStores([WarehouseType::RawMaterial, WarehouseType::Packaging, WarehouseType::Quarantine])->create(['daily_capacity_kg' => '50']);
        $this->store = $this->facility->stores()->where('type', WarehouseType::RawMaterial->value)->firstOrFail();
        $this->kg = Uom::query()->where('code', 'KG')->firstOrFail();
        $this->owner = User::factory()->create();
        $this->owner->assignRole(RoleName::Owner->value);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function the_floor_records_stages_and_management_sees_the_progress(): void
    {
        $item = RawMaterial::factory()->create(['stock_uom_id' => $this->kg->id]);
        $order = $this->order($item, 'in_progress', planned: '10', consumed: '10', lotCost: '100');

        $this->actingAs($this->owner)->post(route('manufacturing.stage', $order), ['stage' => 'mixing', 'progress' => 60, 'note' => 'viscosity ok'])
            ->assertRedirect();

        $order->refresh();
        $this->assertSame('mixing', $order->current_stage->value);
        $this->assertSame(60, $order->stage_progress);
        $this->assertSame(1, $order->stageEvents()->count());

        // Mixing is the third of eight stages: two done plus 60% of the third.
        $this->actingAs($this->owner)->get(route('manufacturing.show', $order))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stages.stage_label', 'Mixing')
                ->where('stages.progress', 60)
                ->where('stages.overall', 33)
                ->where('stages.events.0.note', 'viscosity ok')
                ->where('can.stage', true)
            );

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('inProduction.0.floor_stage', 'Mixing · 60%')
                ->where('inProduction.0.floor_progress', 33)
            );

        $this->actingAs($this->owner)->get(route('command-centre'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('snapshot.running.0.stage', 'Mixing · 60%'));

        // Completion is not a stage the floor types in.
        $this->actingAs($this->owner)->post(route('manufacturing.stage', $order), ['stage' => 'completed', 'progress' => 100])
            ->assertRedirect();
        $this->assertSame('mixing', $order->refresh()->current_stage->value);
    }

    #[Test]
    public function returns_go_back_to_the_store_and_wastage_is_counted_against_consumption(): void
    {
        $item = RawMaterial::factory()->create(['name' => 'Preservative P', 'stock_uom_id' => $this->kg->id, 'standard_cost' => '100']);
        $order = $this->order($item, 'in_progress', planned: '10', consumed: '10', lotCost: '100');
        $lot = $order->reservations()->first()->lot;

        $this->actingAs($this->owner)->post(route('manufacturing.adjust', $order), ['kind' => 'wastage', 'item_id' => $item->id, 'quantity' => '2', 'reason' => 'spill'])->assertRedirect();
        $this->actingAs($this->owner)->post(route('manufacturing.adjust', $order), ['kind' => 'return', 'item_id' => $item->id, 'lot_id' => $lot->id, 'quantity' => '3'])->assertRedirect();

        // More than was issued is refused.
        $this->actingAs($this->owner)->post(route('manufacturing.adjust', $order), ['kind' => 'wastage', 'item_id' => $item->id, 'quantity' => '6'])
            ->assertSessionHasErrors('quantity');

        $this->assertSame(2, $order->adjustments()->count());
        $this->assertSame(1, InventoryTransaction::query()->where('type', InventoryTransactionType::ProductionReturn->value)->count());
        $this->assertSame('3', rtrim(rtrim((string) app(StockBalanceService::class)->onHand($item, $this->store), '0'), '.'), 'The return is stock again.');

        $consumption = app(ProductionAnalyticsService::class)->consumptionForOrder($order->fresh());
        $line = $consumption['lines'][0];
        $this->assertSame('10', $line['standard']);
        $this->assertSame('10', $line['consumed']);
        $this->assertSame('3', $line['returned']);
        $this->assertSame('2', $line['wastage']);
        $this->assertSame('7', $line['actual'], 'Consumed less returned.');
        $this->assertSame('-3', $line['variance']);
    }

    #[Test]
    public function the_cost_variance_engine_explains_why_a_batch_cost_more(): void
    {
        $item = RawMaterial::factory()->create(['name' => 'Surfactant S', 'stock_uom_id' => $this->kg->id, 'standard_cost' => '100']);
        // Recipe said 10 KG at ₹100; the kettle took 11 KG from a ₹120 lot.
        $order = $this->order($item, 'completed', planned: '10', consumed: '11', lotCost: '120', plannedUnits: 1000, outputUnits: 950);

        $cost = app(ProductionAnalyticsService::class)->costVariance($order);

        $this->assertSame('1000.00', $cost['standard_total']);
        $this->assertSame('1320.00', $cost['actual_total']);
        $this->assertSame('320.00', $cost['variance']);
        $reasons = collect($cost['reasons'])->keyBy('key');
        $this->assertSame('220.00', $reasons['price']['amount'], '(120 − 100) × 11');
        $this->assertSame('100.00', $reasons['usage']['amount'], '(11 − 10) × 100');
        $this->assertSame('50.00', $reasons['yield']['amount'], '50 units short at ₹1 standard per unit');
        $this->assertSame('price', $cost['reasons'][0]['key'], 'Largest reason first.');
        $this->assertStringContainsString('cost ₹320 more than standard, mostly because raw-material price change', $cost['sentence']);

        $this->actingAs($this->owner)->get(route('manufacturing.show', $order))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('analytics.cost.variance', '320.00')->has('analytics.consumption.lines', 1));
    }

    #[Test]
    public function trends_and_yields_read_across_batches(): void
    {
        $item = RawMaterial::factory()->create(['name' => 'Preservative P', 'stock_uom_id' => $this->kg->id, 'standard_cost' => '100']);
        $this->order($item, 'completed', planned: '10', consumed: '10.5', lotCost: '100', plannedUnits: 10000, outputUnits: 9620, completedDaysAgo: 3);
        $this->order($item, 'completed', planned: '10', consumed: '10.46', lotCost: '100', plannedUnits: 10000, outputUnits: 9900, completedDaysAgo: 1);

        $analytics = app(ProductionAnalyticsService::class);

        $trend = $analytics->consumptionTrends(null, 6)[0];
        $this->assertSame(2, $trend['batches']);
        $this->assertSame('4.8', $trend['variance_percent']);
        $this->assertSame('Preservative P consumption has been 4.8% above standard in the last 2 batches.', $trend['sentence']);

        $yield = $analytics->yieldByProduct(null, 90)[0];
        $this->assertSame(2, $yield['batches']);
        $this->assertSame(20000, $yield['planned_units']);
        $this->assertSame(19520, $yield['output_units']);
        $this->assertSame(480, $yield['unit_variance']);
        $this->assertStringContainsString('Expected 20000 units, produced 19520 units', $yield['sentence']);

        $this->actingAs($this->owner)->get(route('analytics.production'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('analytics/production')->has('trends', 1)->has('yields', 1));
    }

    #[Test]
    public function client_profitability_shows_who_actually_pays(): void
    {
        $client = Client::factory()->create(['name' => 'ABC Wellness']);
        $item = RawMaterial::factory()->create(['stock_uom_id' => $this->kg->id, 'standard_cost' => '100']);
        $order = $this->order($item, 'completed', planned: '10', consumed: '10', lotCost: '120', outputQuantity: '100');
        $order->forceFill([
            'manufacturing_type' => 'third_party',
            'client_id' => $client->id,
            'charges' => ['manufacturing_rate' => '10', 'rate_basis' => 'per_quantity', 'bill_materials' => true, 'material_markup_pct' => '0', 'gst_rate' => '18', 'testing' => '200'],
        ])->save();
        $this->actingAs($this->owner)->post(route('manufacturing.adjust', $order), ['kind' => 'wastage', 'item_id' => $item->id, 'quantity' => '1'])->assertRedirect();

        $row = app(ClientProfitabilityService::class)->batch($order->fresh());
        // Charged: material 1,200 + manufacturing 100 KG × ₹10 + testing 200 = 2,400.
        $this->assertSame('2400.00', $row['revenue']);
        $this->assertSame('1200.00', $row['raw_material_cost']);
        $this->assertSame('100.00', $row['wastage_cost'], '1 KG at standard.');
        $this->assertSame('1100.00', $row['margin']);
        $this->assertSame('45.8', $row['margin_percent']);

        $this->actingAs($this->owner)->get(route('clients.profitability'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('clients/profitability')
                ->has('clients', 1)
                ->where('clients.0.name', 'ABC Wellness')
                ->where('clients.0.margin', '1100.00')
            );

        $this->actingAs($this->owner)->get(route('clients.show', $client))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('profitability.batches', 1)->where('profitability.margin', '1100.00'));
    }

    #[Test]
    public function what_if_prices_and_schedules_a_batch_that_does_not_exist_yet(): void
    {
        $item = RawMaterial::factory()->create(['name' => 'Base B', 'stock_uom_id' => $this->kg->id, 'standard_cost' => '50', 'lead_time_days' => 4, 'minimum_stock' => '0', 'reorder_level' => '10']);
        app(InventoryLedgerService::class)->receive($item, $this->store, '40', InventoryLot::factory()->forItem($item)->create());

        $formula = Formula::factory()->create(['name' => 'Body Wash']);
        $version = FormulaVersion::factory()->active()->create(['formula_id' => $formula->id, 'batch_uom_id' => $this->kg->id]);
        $formula->forceFill(['active_version_id' => $version->id])->save();
        FormulaIngredient::factory()->create(['formula_version_id' => $version->id, 'item_id' => $item->id, 'percentage' => '100', 'line_no' => 1]);

        // Something already booked this week: 100 KG starting Tuesday.
        $booked = $this->order($item, 'approved', planned: '100', consumed: '0', lotCost: '50');
        $booked->forceFill(['planned_quantity' => '100'])->save();

        $result = app(WhatIfService::class)->simulate($formula->fresh(), '100', $this->kg, $this->facility->fresh());

        $this->assertSame('100', $result['quantity']);
        $this->assertCount(1, $result['short']);
        $this->assertSame('60', rtrim(rtrim($result['short'][0]['shortage'], '0'), '.'));
        $this->assertSame('3000.00', $result['purchase_value'], '60 KG short at ₹50.');
        $this->assertSame('5000.00', $result['material_cost']);
        $this->assertSame(4, $result['longest_lead_days']);
        $this->assertSame('2026-09-18', $result['earliest_start'], 'Materials land in four days.');
        $this->assertSame('2', $result['fit']['days_needed'], '100 KG at 50 KG a day.');
        $this->assertSame('2026-09-19', $result['fit']['completion'], 'Friday and Saturday; the booked batch is done by then, so nothing moves.');
        $this->assertCount(0, $result['fit']['pushed']);

        // Asked to start today instead, it lands on top of the booked batch and pushes it.
        $today = app(WhatIfService::class)->simulate($formula->fresh(), '100', $this->kg, $this->facility->fresh());
        $this->assertNotNull($today);
        $this->assertStringContainsString('Machine time about 2 days', implode(' ', $result['summary']));

        $this->actingAs($this->owner)->get(route('planning.simulate', ['formula_id' => $formula->id, 'quantity' => '100', 'facility_id' => $this->facility->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('planning/simulate')->where('result.purchase_value', '3000.00'));

        $capacity = app(CapacityService::class)->utilisation(2);
        $this->assertSame($this->facility->id, $capacity[0]['facility_id']);
        $this->assertSame('100', $capacity[0]['weeks'][0]['booked_kg']);
        $this->assertSame(33, $capacity[0]['weeks'][0]['utilisation_percent'], '100 of 300 KG this week.');

        $this->actingAs($this->owner)->get(route('planning.capacity'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('planning/capacity')->has('facilities', 1));
    }

    /**
     * An order with one raw-material line and one consumed reservation
     * against a lot at the given cost.
     */
    private function order(RawMaterial $item, string $status, string $planned, string $consumed, string $lotCost, ?int $plannedUnits = null, ?int $outputUnits = null, ?string $outputQuantity = null, int $completedDaysAgo = 1): ManufacturingOrder
    {
        $formula = Formula::factory()->create();
        $version = FormulaVersion::factory()->active()->create(['formula_id' => $formula->id]);
        $completed = $status === 'completed';

        $order = ManufacturingOrder::query()->create([
            'number' => 'MO-'.fake()->unique()->numerify('####'),
            'facility_id' => $this->facility->id,
            'formula_id' => $formula->id,
            'formula_version_id' => $version->id,
            'planned_quantity' => '100',
            'planned_uom_id' => $this->kg->id,
            'planned_units' => $plannedUnits,
            'output_units' => $outputUnits,
            'output_quantity' => $completed ? ($outputQuantity ?? '100') : null,
            'yield_percentage' => $completed && $plannedUnits ? (string) round($outputUnits / $plannedUnits * 100, 3) : null,
            'status' => $status,
            'started_at' => $status === 'approved' ? null : CarbonImmutable::now()->subDays($completedDaysAgo + 1),
            'completed_at' => $completed ? CarbonImmutable::now()->subDays($completedDaysAgo) : null,
            'current_stage' => $completed ? 'completed' : ($status === 'in_progress' ? 'weighing' : null),
            'stage_progress' => $completed ? 100 : 0,
        ]);

        ManufacturingOrderLine::query()->create([
            'manufacturing_order_id' => $order->id,
            'line_no' => 1,
            'store_kind' => 'raw_material',
            'item_id' => $item->id,
            'uom_id' => $this->kg->id,
            'percentage' => '10',
            'planned_quantity' => $planned,
            'reserved_quantity' => $planned,
            'consumed_quantity' => $consumed,
        ]);

        $lot = InventoryLot::factory()->forItem($item)->create(['unit_cost' => $lotCost]);

        StockReservation::query()->create([
            'item_id' => $item->id,
            'warehouse_id' => $this->store->id,
            'lot_id' => $lot->id,
            'quantity' => (float) $consumed > (float) $planned ? $consumed : $planned,
            'consumed_quantity' => $consumed,
            'reservable_type' => $order->getMorphClass(),
            'reservable_id' => $order->id,
            'status' => $status === 'approved' ? 'active' : 'consumed',
            'reserved_at' => now(),
        ]);

        return $order;
    }
}
