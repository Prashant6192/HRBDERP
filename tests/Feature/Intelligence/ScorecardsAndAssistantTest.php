<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Contract\Models\Client;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Formulation\Services\FormulaService;
use App\Domain\Intelligence\Assistant\AssistantBackend;
use App\Domain\Intelligence\Assistant\AssistantTools;
use App\Domain\Intelligence\Assistant\ErpAssistant;
use App\Domain\Intelligence\Services\ScorecardService;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Inventory\Services\StockCountService;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Manufacturing\Services\ManufacturingOrderService;
use App\Domain\MasterData\Models\PackagingMaterial;
use App\Domain\MasterData\Models\Product;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Planning\Services\ProductionPlanService;
use App\Domain\Quality\Models\QcInspection;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScorecardsAndAssistantTest extends TestCase
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

        $this->facility = Facility::factory()->manufacturing()->create(['code' => 'FAC-SCR-001', 'name' => 'Scorecard Plant']);
        $this->rmStore = Warehouse::factory()->atFacility($this->facility)->create(['code' => 'WH-RM', 'type' => WarehouseType::RawMaterial]);
        $this->pmStore = Warehouse::factory()->atFacility($this->facility)->create(['code' => 'WH-PM', 'type' => WarehouseType::Packaging]);
        Warehouse::factory()->atFacility($this->facility)->create(['code' => 'WH-FG', 'type' => WarehouseType::FinishedGoods]);
        Warehouse::factory()->atFacility($this->facility)->quarantine()->create(['code' => 'WH-QA']);

        $this->kg = Uom::where('code', 'KG')->firstOrFail();
        $ml = Uom::where('code', 'ML')->firstOrFail();
        $pcs = Uom::where('code', 'PCS')->firstOrFail();

        $this->surfactant = RawMaterial::factory()->create(['code' => 'RM-SURF-A', 'name' => 'Surfactant A', 'stock_uom_id' => $this->kg->id, 'reorder_level' => null, 'minimum_stock' => null, 'standard_cost' => '100']);
        $this->water = RawMaterial::factory()->create(['name' => 'Purified Water', 'stock_uom_id' => $this->kg->id, 'density_g_per_ml' => '1', 'reorder_level' => null, 'minimum_stock' => null, 'standard_cost' => '1']);
        $this->bottle = PackagingMaterial::factory()->create(['name' => 'Bottle 100 ml', 'stock_uom_id' => $pcs->id, 'reorder_level' => null, 'minimum_stock' => null]);

        $product = Product::factory()->create(['name' => 'Scorecard Face Wash 100 ml', 'net_content' => '100', 'net_content_uom_id' => $ml->id, 'stock_uom_id' => $pcs->id, 'requires_qc' => true, 'shelf_life_days' => 730]);
        $product->packagingLines()->create(['packaging_material_id' => $this->bottle->id, 'quantity_per_unit' => '1']);

        $formulas = app(FormulaService::class);
        $this->formula = $formulas->create([
            'name' => 'Scorecard Face Wash', 'product_id' => $product->id, 'batch_uom_id' => Uom::where('code', 'G')->value('id'),
        ], [
            ['item_id' => $this->surfactant->id, 'percentage' => '15'],
            ['item_id' => $this->water->id, 'is_qs' => true],
        ], $this->factoryManager->id);
        $formulas->activate($this->formula->versions()->first(), $this->factoryManager->id);
        $this->formula->refresh();

        $this->ledger->receive($this->surfactant, $this->rmStore, '200', InventoryLot::factory()->forItem($this->surfactant)->create(['batch_number' => 'SURF-001', 'qc_status' => LotQcStatus::Approved, 'expiry_at' => now()->addYear()]), unitCost: '100', userId: $this->factoryManager->id);
        $this->ledger->receive($this->water, $this->rmStore, '2000', InventoryLot::factory()->forItem($this->water)->create(['batch_number' => 'WATER-001', 'qc_status' => LotQcStatus::Approved, 'expiry_at' => now()->addYear()]), unitCost: '1', userId: $this->factoryManager->id);
        $this->ledger->receive($this->bottle, $this->pmStore, '20000', InventoryLot::factory()->forItem($this->bottle)->create(['batch_number' => 'BTL-001', 'qc_status' => LotQcStatus::Approved]), userId: $this->factoryManager->id);
    }

    private function user(RoleName $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role->value);

        return $user;
    }

    private function startedOrder(): ManufacturingOrder
    {
        $plan = app(ProductionPlanService::class)->create(['formula_id' => $this->formula->id, 'quantity' => '100', 'uom_id' => $this->kg->id], $this->productionManager->id);
        $order = $this->orders->approve($this->orders->createFromPlan($plan, $this->productionManager->id), $this->factoryManager->id);

        return $this->orders->start($order, $this->productionManager->id);
    }

    #[Test]
    public function scorecards_read_otif_yield_qc_and_inventory_accuracy_from_the_documents(): void
    {
        $client = Client::factory()->create(['name' => 'Rozz Beauty']);

        // A client job delivered on time and in full ...
        $first = $this->startedOrder();
        $first->forceFill(['client_id' => $client->id, 'manufacturing_type' => 'third_party', 'required_delivery_at' => now()->addDays(3)->toDateString()])->save();
        $this->orders->complete($first, $this->productionManager->id, ['output_quantity' => '100', 'output_units' => 1000, 'manufactured_at' => now()->toDateString()]);

        // ... and one that was short and late.
        $second = $this->startedOrder();
        $second->forceFill(['client_id' => $client->id, 'manufacturing_type' => 'third_party', 'required_delivery_at' => now()->subDays(2)->toDateString()])->save();
        $this->orders->complete($second, $this->productionManager->id, ['output_quantity' => '90', 'output_units' => 900, 'manufactured_at' => now()->toDateString()]);

        // A QC decision three hours after the sample was logged.
        $lot = InventoryLot::factory()->forItem($this->surfactant)->pendingQc()->create(['batch_number' => 'SURF-QC']);
        $inspection = QcInspection::query()->create(['number' => 'QCI-SC', 'lot_id' => $lot->id, 'item_id' => $this->surfactant->id, 'quantity' => '10', 'status' => LotQcStatus::Approved, 'destination_warehouse_id' => $this->rmStore->id, 'decided_by' => $this->factoryManager->id, 'decided_at' => now()]);
        $inspection->forceFill(['created_at' => now()->subHours(3)])->save();

        // A stock count where one of two lines matched.
        $counter = $this->user(RoleName::StoreExecutive);
        $checker = $this->user(RoleName::WarehouseManager);
        $counts = app(StockCountService::class);
        $count = $counts->start($this->rmStore, $counter->id);
        foreach ($count->lines as $line) {
            $counts->record($count, $line->item, $line->lot, $line->item_id === $this->water->id ? $line->system_quantity : '1', $counter->id);
        }
        $counts->approve($counts->submit($count, $counter->id), $checker->id);

        $cards = app(ScorecardService::class)->build($this->facility, 30);
        $byKey = collect($cards['departments'])->keyBy('key');
        $kpi = fn (string $dept, string $key) => collect($byKey[$dept]['kpis'])->firstWhere('key', $key)['value'];

        $this->assertSame('50.0', $kpi('dispatch', 'otif'));
        $this->assertSame('50.0', $kpi('dispatch', 'on_time'));
        $this->assertSame('50.0', $kpi('dispatch', 'in_full'));
        $this->assertSame('2', $kpi('manufacturing', 'batches'));
        $this->assertSame('95.0', $kpi('manufacturing', 'yield'), 'Average of 100% and 90%');
        $this->assertSame('3.0', $kpi('qc', 'turnaround'));
        $this->assertSame('0.0', $kpi('qc', 'rejection'));
        $this->assertSame('50.0', $kpi('store', 'inventory_accuracy'));
        $this->assertSame('2', $kpi('planning', 'plans'));
        $this->assertSame('50.0', $cards['headline']['otif_percent']);

        $batchStep = collect($cards['processes'])->firstWhere('key', 'batch');
        $this->assertSame(2, $batchStep['volume']);
        $this->assertSame(0, $batchStep['open']);

        // Another plant sees none of it.
        $other = Facility::factory()->manufacturing()->create(['code' => 'FAC-OTHER']);
        $this->assertNull(app(ScorecardService::class)->build($other, 30)['headline']['otif_percent']);

        $this->actingAs($this->factoryManager)->get(route('scorecards', ['days' => 30]))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('analytics/scorecards')
                ->where('scorecards.headline.otif_percent', '50.0')
                ->has('scorecards.departments', 7)
                ->has('scorecards.processes', 8)
                ->where('filters.days', 30));
    }

    #[Test]
    public function the_assistant_answers_from_the_erp_through_read_only_tools_under_the_askers_permissions(): void
    {
        $backend = new class implements AssistantBackend
        {
            /** @var list<array<string, mixed>> */
            public array $seen = [];

            public string $system = '';

            public function turn(string $system, array $tools, array $messages): array
            {
                $this->seen[] = $messages;
                $this->system = $system;
                $last = end($messages);

                if (! is_array($last['content'])) {
                    return [
                        'stop_reason' => 'tool_use',
                        'blocks' => [
                            ['type' => 'text', 'text' => 'Let me check the stock.'],
                            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'stock_outlook', 'input' => ['item_code' => 'RM-SURF-A']],
                        ],
                        'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'stock_outlook', 'input' => ['item_code' => 'RM-SURF-A']]],
                        'usage' => ['input' => 10, 'output' => 5],
                    ];
                }

                $result = json_decode($last['content'][0]['content'], true);

                return [
                    'stop_reason' => 'end_turn',
                    'blocks' => [['type' => 'text', 'text' => "Surfactant A: {$result['on_hand']} KG on hand. ".$result['sentences'][0]]],
                    'content' => [['type' => 'text', 'text' => 'ignored']],
                    'usage' => ['input' => 20, 'output' => 8],
                ];
            }
        };
        $this->app->instance(AssistantBackend::class, $backend);

        $this->actingAs($this->factoryManager)->get(route('assistant.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('assistant/index')->where('available', true)->has('suggestions'));

        $reply = $this->actingAs($this->factoryManager)->postJson(route('assistant.ask'), [
            'question' => 'How much Surfactant A do we have?',
            'history' => [['role' => 'user', 'content' => 'Hello'], ['role' => 'assistant', 'content' => 'Hi. What do you want to know?']],
        ])->assertOk();

        $reply->assertJsonPath('tools.0.name', 'stock_outlook')->assertJsonPath('usage.input', 30);
        $this->assertStringContainsString('200 KG on hand', $reply->json('answer'));

        // The history went in ahead of the question; the tool result went back as a tool_result.
        $this->assertCount(3, $backend->seen[0]);
        $this->assertSame('toolu_1', $backend->seen[1][4]['content'][0]['toolUseID']);
        $this->assertSame('Hello', $backend->seen[0][0]['content']);
        $this->assertStringContainsString('never change anything', $backend->system);

        // Tools answer only what the asker may see.
        $tools = app(AssistantTools::class);
        $packer = $this->user(RoleName::PackagingExecutive);
        $this->assertStringContainsString('do not hold inventory.view', $tools->run($packer, 'stock_outlook', ['item_code' => 'RM-SURF-A']));
        $this->assertStringContainsString('do not hold report.view', $tools->run($packer, 'scorecards', []));
        $this->assertStringContainsString('"matches"', $tools->run($this->factoryManager, 'find_items', ['query' => 'surf']));
        $this->assertStringContainsString('No manufacturing order', $tools->run($this->factoryManager, 'order_status', ['number' => 'MO-NOPE']));
        $this->assertStringContainsString('"summary"', $tools->run($this->factoryManager, 'trace_batch', ['batch_number' => 'SURF-001']));
        $this->assertStringContainsString('"departments"', $tools->run($this->factoryManager, 'scorecards', ['days' => 7]));
        $this->assertStringContainsString('"running"', $tools->run($this->factoryManager, 'command_centre', []));
        $this->assertStringContainsString('"exceptions"', $tools->run($this->factoryManager, 'factory_exceptions', []));
        $this->assertStringContainsString('"slow_moving"', $tools->run($this->factoryManager, 'stock_risks', []));
        $this->assertStringContainsString('"items"', $tools->run($this->factoryManager, 'reorder_advice', []));

        // Nobody without the permission reaches it at all.
        $this->actingAs($packer)->get(route('assistant.index'))->assertForbidden();
        $this->actingAs($packer)->postJson(route('assistant.ask'), ['question' => 'anything'])->assertForbidden();
    }

    #[Test]
    public function without_an_api_key_the_assistant_says_it_is_switched_off(): void
    {
        config(['erp.ai.api_key' => null]);

        $this->actingAs($this->factoryManager)->get(route('assistant.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('available', false));

        $this->actingAs($this->factoryManager)->postJson(route('assistant.ask'), ['question' => 'How much stock?'])
            ->assertStatus(503)->assertJsonPath('error', fn (string $e) => str_contains($e, 'ANTHROPIC_API_KEY'));

        $this->assertNotEmpty(app(ErpAssistant::class)->system($this->factoryManager));
    }
}
