<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Formulation\Models\FormulaVersion;
use App\Domain\Intelligence\Models\Escalation;
use App\Domain\Intelligence\Services\ExceptionService;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Manufacturing\Models\ManufacturingOrderLine;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Planning\Models\ProductionPlan;
use App\Domain\Planning\Models\ProductionPlanLine;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Procurement\Models\GoodsReceiptLine;
use App\Domain\Procurement\Models\Vendor;
use App\Domain\Quality\Models\QcInspection;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use App\Notifications\ErpAlert;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CommandCentreTest extends TestCase
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

        $this->facility = Facility::factory()->manufacturing()->withStores([WarehouseType::RawMaterial, WarehouseType::Quarantine])->create();
        $this->store = $this->facility->stores()->where('type', WarehouseType::RawMaterial->value)->firstOrFail();
        $this->quarantine = $this->facility->stores()->where('is_quarantine', true)->firstOrFail();
        $this->kg = Uom::query()->where('code', 'KG')->firstOrFail();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function the_exception_engine_surfaces_only_what_crossed_a_threshold(): void
    {
        $item = RawMaterial::factory()->create(['name' => 'Preservative P', 'stock_uom_id' => $this->kg->id]);

        // A QC decision pending 7 hours is slow; one pending 2 hours is not.
        $this->inspection($item, hoursAgo: 7, number: 'QCI-SLOW');
        $this->inspection($item, hoursAgo: 2, number: 'QCI-FRESH');

        // A batch running for 60 hours is delayed; 10 hours is not.
        $this->order($item, status: 'in_progress', startedHoursAgo: 60, number: 'MO-LATE');
        $this->order($item, status: 'in_progress', startedHoursAgo: 10, number: 'MO-FINE');

        // A completed batch at 91% yield is below the 95% target.
        $this->order($item, status: 'completed', number: 'MO-LOW', yield: '91.0', plannedUnits: 10000, outputUnits: 9100, planned: '100', consumed: '108');

        // Price jumped 25% on the latest delivery.
        $vendor = Vendor::factory()->create(['name' => 'Chem Traders']);
        $this->delivery($item, $vendor, '100.00', CarbonImmutable::now()->subDays(60), 'GRN-OLD1');
        $this->delivery($item, $vendor, '100.00', CarbonImmutable::now()->subDays(40), 'GRN-OLD2');
        $this->delivery($item, $vendor, '125.00', CarbonImmutable::now()->subDays(2), 'GRN-NEW');

        $exceptions = app(ExceptionService::class)->detect()->keyBy(fn ($e) => $e->key());

        $this->assertTrue($exceptions->has('slow_qc:QCI-SLOW'));
        $this->assertFalse($exceptions->has('slow_qc:QCI-FRESH'));
        $this->assertTrue($exceptions->has('delayed_batch:MO-LATE'));
        $this->assertFalse($exceptions->has('delayed_batch:MO-FINE'));
        $this->assertTrue($exceptions->has('production_below_target:MO-LOW'));
        $this->assertStringContainsString('expected 10000 units, produced 9100 — 900 short', $exceptions['production_below_target:MO-LOW']->detail);
        $this->assertTrue($exceptions->has("material_variance:{$item->code}"));
        $this->assertStringContainsString('8.0% above standard', $exceptions["material_variance:{$item->code}"]->title);

        $price = $exceptions->first(fn ($e) => $e->rule === 'price_increase');
        $this->assertNotNull($price);
        $this->assertSame('25.0', $price->metrics['increase_percent']);
        $this->assertStringContainsString('Chem Traders', $price->detail);
    }

    #[Test]
    public function the_command_centre_answers_the_seven_questions_for_management(): void
    {
        $item = RawMaterial::factory()->create(['name' => 'Surfactant S', 'stock_uom_id' => $this->kg->id, 'minimum_stock' => '0', 'reorder_level' => '10', 'lead_time_days' => 5]);
        $this->inspection($item, hoursAgo: 8, number: 'QCI-0001');
        $running = $this->order($item, status: 'in_progress', startedHoursAgo: 3, number: 'MO-RUN');
        $this->order($item, status: 'in_progress', startedHoursAgo: 72, number: 'MO-LATE');

        // A plan due to start tomorrow needing 50 KG of which none is in stock.
        $this->planShort($item, '50', CarbonImmutable::now()->addDay()->toDateString());

        $management = User::factory()->create();
        $management->assignRole(RoleName::Management->value);

        $this->actingAs($management)->get(route('command-centre'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('command-centre')
                ->has('snapshot.running', 2)
                // Longest on the floor first.
                ->where('snapshot.running.0.number', 'MO-LATE')
                ->where('snapshot.running.0.late', true)
                ->where('snapshot.running.1.number', 'MO-RUN')
                ->where('snapshot.running.1.late', false)
                ->has('snapshot.delayed', 1)
                ->has('snapshot.awaiting_qc', 1)
                ->where('snapshot.awaiting_qc.0.number', 'QCI-0001')
                ->where('snapshot.awaiting_qc.0.slow', true)
                ->has('snapshot.short', 1)
                ->where('snapshot.short.0.name', 'Surfactant S')
                ->where('snapshot.short.0.shortage', '50')
                ->has('snapshot.tomorrow', 1)
                ->where('snapshot.tomorrow.0.kind', 'material')
                ->where('snapshot.tomorrow.0.title', 'Surfactant S short by 50 KG')
                ->where('snapshot.counts.order_today', 1)
                ->has('snapshot.exceptions')
            );

        $this->assertNotNull($running);

        // The floor does not get the management screen.
        $operator = User::factory()->create();
        $operator->assignRole(RoleName::ProductionOperator->value);
        $this->actingAs($operator)->get(route('command-centre'))->assertForbidden();

        // The dashboard carries the top exceptions for those who may see reports.
        $this->actingAs($management)->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('exceptions.total', fn ($n) => $n >= 2));
    }

    #[Test]
    public function escalation_climbs_the_ladder_once_per_level_and_closes_when_the_condition_clears(): void
    {
        Notification::fake();

        $qcManager = User::factory()->create(['name' => 'Meera QC']);
        $qcManager->assignRole(RoleName::QcManager->value);
        $plantHead = User::factory()->create(['name' => 'Suresh Plant']);
        $plantHead->assignRole(RoleName::FactoryManager->value);

        $item = RawMaterial::factory()->create(['stock_uom_id' => $this->kg->id]);
        $inspection = $this->inspection($item, hoursAgo: 7, number: 'QCI-ESC');

        // Seven hours pending: the QC Manager hears, the plant head does not yet.
        $this->artisan('erp:escalate')->assertSuccessful();
        Notification::assertSentTo($qcManager, ErpAlert::class, fn (ErpAlert $n) => str_contains($n->title, 'QCI-ESC') && $n->category === 'escalation');
        Notification::assertNotSentTo($plantHead, ErpAlert::class);
        $this->assertSame(1, Escalation::query()->count());

        // An hour later nothing new is raised: level one fired already.
        CarbonImmutable::setTestNow('2026-09-14 10:00:00');
        $this->artisan('erp:escalate')->assertSuccessful();
        Notification::assertSentToTimes($qcManager, ErpAlert::class, 1);

        // Thirteen hours pending: the plant head hears.
        CarbonImmutable::setTestNow('2026-09-14 15:30:00');
        $this->artisan('erp:escalate')->assertSuccessful();
        Notification::assertSentTo($plantHead, ErpAlert::class);
        $this->assertSame(2, Escalation::query()->where('exception_key', 'slow_qc:QCI-ESC')->count());

        // QC decides; the escalation closes.
        $inspection->update(['status' => LotQcStatus::Approved, 'decided_at' => now()]);
        $this->artisan('erp:escalate')->assertSuccessful();
        $this->assertSame(0, Escalation::query()->standing()->count());
        $this->assertNotNull(Escalation::query()->first()->resolved_at);
    }

    #[Test]
    public function notifications_reach_the_bell_and_can_be_read(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::QcManager->value);
        $user->notify(new ErpAlert('QCI-0007 waiting for QC for 7 hours', 'Since this morning.', route('qc.index'), 'medium', 'escalation', 'slow_qc:QCI-0007'));
        $user->notify(new ErpAlert('Something else', 'Details.', null, 'low', 'exception'));

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('notifications.unread', 2)
                ->has('notifications.latest', 2)
            );

        $this->actingAs($user)->get(route('notifications.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('notifications/index')->has('notifications.data', 2)->where('unread', 2));

        $first = $user->notifications()->get()->first(fn ($n) => $n->data['key'] === 'slow_qc:QCI-0007');
        $this->actingAs($user)->post(route('notifications.read', ['notification' => $first->id, 'open' => 1]))
            ->assertRedirect(route('qc.index'));
        $this->assertSame(1, $user->unreadNotifications()->count());

        $this->actingAs($user)->post(route('notifications.read-all'))->assertRedirect();
        $this->assertSame(0, $user->unreadNotifications()->count());

        // Another person's notification is not this person's to read.
        $other = User::factory()->create();
        $this->actingAs($other)->post(route('notifications.read', ['notification' => $first->id]))->assertNotFound();
    }

    private function inspection(RawMaterial $item, int $hoursAgo, string $number): QcInspection
    {
        $lot = InventoryLot::factory()->forItem($item)->pendingQc()->create();

        $inspection = QcInspection::query()->create([
            'number' => $number,
            'lot_id' => $lot->id,
            'item_id' => $item->id,
            'quantity' => '25',
            'status' => LotQcStatus::Pending,
            'destination_warehouse_id' => $this->store->id,
        ]);
        $inspection->forceFill(['created_at' => CarbonImmutable::now()->subHours($hoursAgo), 'updated_at' => CarbonImmutable::now()->subHours($hoursAgo)])->saveQuietly();

        return $inspection;
    }

    private function order(RawMaterial $item, string $status, string $number, ?int $startedHoursAgo = null, ?string $yield = null, ?int $plannedUnits = null, ?int $outputUnits = null, string $planned = '10', string $consumed = '10'): ManufacturingOrder
    {
        $formula = Formula::factory()->create();
        $version = FormulaVersion::factory()->active()->create(['formula_id' => $formula->id]);

        $order = ManufacturingOrder::query()->create([
            'number' => $number,
            'facility_id' => $this->facility->id,
            'formula_id' => $formula->id,
            'formula_version_id' => $version->id,
            'planned_quantity' => '100',
            'planned_uom_id' => $this->kg->id,
            'planned_units' => $plannedUnits,
            'output_units' => $outputUnits,
            'yield_percentage' => $yield,
            'status' => $status,
            'started_at' => $startedHoursAgo !== null ? CarbonImmutable::now()->subHours($startedHoursAgo) : ($status === 'completed' ? CarbonImmutable::now()->subDays(2) : null),
            'completed_at' => $status === 'completed' ? CarbonImmutable::now()->subDay() : null,
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
            'consumed_quantity' => $status === 'completed' ? $consumed : $planned,
        ]);

        return $order;
    }

    private function planShort(RawMaterial $item, string $required, string $start): void
    {
        $formula = Formula::factory()->create();
        $version = FormulaVersion::factory()->active()->create(['formula_id' => $formula->id]);

        $plan = ProductionPlan::query()->create([
            'number' => 'PP-'.fake()->unique()->numerify('####'),
            'facility_id' => $this->facility->id,
            'formula_id' => $formula->id,
            'formula_version_id' => $version->id,
            'planned_quantity' => '100',
            'planned_uom_id' => $this->kg->id,
            'status' => 'checked',
            'planned_start_date' => $start,
            'checked_at' => now(),
        ]);

        ProductionPlanLine::query()->create([
            'production_plan_id' => $plan->id,
            'line_no' => 1,
            'store_kind' => 'raw_material',
            'source' => 'company',
            'item_id' => $item->id,
            'uom_id' => $this->kg->id,
            'percentage' => '50',
            'required_quantity' => $required,
            'available_quantity' => '0',
            'shortage_quantity' => $required,
            'level_now' => 'out_of_stock',
            'level_after' => 'out_of_stock',
        ]);
    }

    private function delivery(RawMaterial $item, Vendor $vendor, string $price, CarbonImmutable $postedAt, string $number): void
    {
        $receipt = GoodsReceipt::query()->create([
            'number' => $number,
            'vendor_id' => $vendor->id,
            'warehouse_id' => $this->quarantine->id,
            'received_at' => $postedAt->toDateString(),
            'status' => 'received',
            'posted_at' => $postedAt,
        ]);

        GoodsReceiptLine::query()->create([
            'goods_receipt_id' => $receipt->id,
            'item_id' => $item->id,
            'quantity' => '10',
            'uom_id' => $this->kg->id,
            'stock_quantity' => '10',
            'unit_price' => $price,
        ]);
    }
}
