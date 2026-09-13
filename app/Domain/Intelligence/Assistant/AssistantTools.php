<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Assistant;

use App\Domain\Contract\Services\ClientProfitabilityService;
use App\Domain\Intelligence\DTOs\FactoryException;
use App\Domain\Intelligence\DTOs\ItemOutlook;
use App\Domain\Intelligence\Services\CommandCentreService;
use App\Domain\Intelligence\Services\ExceptionService;
use App\Domain\Intelligence\Services\ExpiryRiskService;
use App\Domain\Intelligence\Services\ScorecardService;
use App\Domain\Intelligence\Services\SlowMovingStockService;
use App\Domain\Intelligence\Services\StockOutlookService;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Services\RecallTraceService;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Manufacturing\Services\IssueVerificationService;
use App\Domain\Manufacturing\Services\ProductionStageService;
use App\Domain\MasterData\Models\Item;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Models\User;
use App\Support\Math\Decimal;
use Carbon\CarbonImmutable;

/**
 * What the assistant may look at. Every tool reads; none writes. Each
 * checks the asking person's own permissions, so the assistant never
 * shows anyone more than the screens would.
 */
class AssistantTools
{
    public function __construct(
        private readonly StockOutlookService $outlook,
        private readonly ExceptionService $exceptions,
        private readonly CommandCentreService $commandCentre,
        private readonly ScorecardService $scorecards,
        private readonly SlowMovingStockService $slowMoving,
        private readonly ExpiryRiskService $expiryRisk,
        private readonly RecallTraceService $trace,
        private readonly ClientProfitabilityService $profitability,
        private readonly ProductionStageService $stages,
        private readonly IssueVerificationService $verification,
        private readonly FacilityAccess $access,
    ) {}

    /**
     * Tool definitions in the API's shape.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        $facility = ['type' => 'integer', 'description' => 'Optional facility id to limit the answer to one plant.'];

        return [
            $this->tool('find_items', 'Search raw materials, packaging materials and products by code or name. Returns up to 10 matches with the quantity on hand. Use this first when the person names a material loosely.', [
                'query' => ['type' => 'string', 'description' => 'Part of the code or name, e.g. "surfactant" or "RM-001".'],
            ], ['query']),
            $this->tool('stock_outlook', 'The full outlook for one material: on hand, reserved, usable, upcoming requirement, rate of use, lead time, run-out date, shortfall, recommended order and the supplier to order from. Answers "do we have enough", "when do we run out", "what should we order".', [
                'item_code' => ['type' => 'string', 'description' => 'The exact item code (get it from find_items).'],
                'facility_id' => $facility,
            ], ['item_code']),
            $this->tool('reorder_advice', 'Every material that needs ordering now or this week, most urgent first, with recommended quantity, order-by date and supplier.', [
                'facility_id' => $facility,
            ]),
            $this->tool('factory_exceptions', 'What has crossed a threshold right now: delayed batches, abnormal wastage, slow QC, shortages, late transfers, unexpected price rises and what could stop production tomorrow.', [
                'facility_id' => $facility,
            ]),
            $this->tool('command_centre', 'The live picture: batches running with their stage and progress, what is waiting for QC, what is short, client jobs due, approvals pending, and the headline counts.', [
                'facility_id' => $facility,
            ]),
            $this->tool('order_status', 'One manufacturing order: status, product, quantities, stage and progress, material lines with planned/reserved/consumed, what has been verified by scan, and its stage history.', [
                'number' => ['type' => 'string', 'description' => 'The order number, e.g. MO-2609-00012.'],
            ], ['number']),
            $this->tool('trace_batch', 'Recall trace for a batch number: forwards through every batch it went into to the finished goods and where they are now; backwards to what it was made from. Use for "where did this lot go" and "what went into this batch".', [
                'batch_number' => ['type' => 'string'],
            ], ['batch_number']),
            $this->tool('scorecards', 'Department scorecards for the last N days: planning adherence, purchase lead-time adherence, store booking time and inventory accuracy, QC turnaround and rejection, manufacturing yield and delays, packaging rejection, dispatch OTIF (on time in full), and the time each process step takes.', [
                'days' => ['type' => 'integer', 'description' => '7, 30, 90 or 365. Default 30.'],
                'facility_id' => $facility,
            ]),
            $this->tool('stock_risks', 'Slow-moving stock (idle 30/60/90/180+ days with blocked working capital) and expiry risk (batches likely to expire before they are used, with value at risk).', [
                'facility_id' => $facility,
            ]),
            $this->tool('client_profitability', 'Revenue, cost and margin per contract-manufacturing client over the last N days.', [
                'days' => ['type' => 'integer', 'description' => 'Default 365.'],
            ]),
        ];
    }

    /**
     * Run one tool for one person. Returns the JSON the model reads.
     *
     * @param  array<string, mixed>  $input
     */
    public function run(User $user, string $name, array $input): string
    {
        $result = match ($name) {
            'find_items' => $this->findItems($user, (string) ($input['query'] ?? '')),
            'stock_outlook' => $this->stockOutlook($user, (string) ($input['item_code'] ?? ''), $this->facility($user, $input)),
            'reorder_advice' => $this->reorderAdvice($user, $this->facility($user, $input)),
            'factory_exceptions' => $this->factoryExceptions($user, $this->facility($user, $input)),
            'command_centre' => $this->commandCentreSnapshot($user, $this->facility($user, $input)),
            'order_status' => $this->orderStatus($user, (string) ($input['number'] ?? '')),
            'trace_batch' => $this->traceBatch($user, (string) ($input['batch_number'] ?? '')),
            'scorecards' => $this->scorecardsFor($user, (int) ($input['days'] ?? 30), $this->facility($user, $input)),
            'stock_risks' => $this->stockRisks($user, $this->facility($user, $input)),
            'client_profitability' => $this->clientProfitability($user, (int) ($input['days'] ?? 365)),
            default => ['error' => "There is no tool called {$name}."],
        };

        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $limit = (int) config('erp.ai.assistant.tool_result_chars', 14000);

        return mb_strlen($json) > $limit ? mb_substr($json, 0, $limit).'…(truncated; ask for something narrower)' : $json;
    }

    // -- Tools -----------------------------------------------------------------

    private function findItems(User $user, string $query): array
    {
        if (! $user->can('inventory.view') && ! $user->can('raw_material.view') && ! $user->can('product.view')) {
            return $this->denied('inventory.view');
        }

        $items = Item::query()->search($query)->with('stockUom:id,code')->orderBy('name')->limit(10)->get(['id', 'code', 'name', 'type', 'stock_uom_id', 'is_active']);
        $onHand = StockBalance::query()->whereIn('item_id', $items->pluck('id'))->selectRaw('item_id, SUM(on_hand) AS on_hand')->groupBy('item_id')->pluck('on_hand', 'item_id');

        return [
            'matches' => $items->map(fn (Item $i) => [
                'code' => $i->code, 'name' => $i->name, 'type' => $i->type->value, 'unit' => $i->stockUom?->code,
                'on_hand' => Decimal::strip((string) ($onHand[$i->id] ?? '0')), 'active' => $i->is_active,
            ])->all(),
        ];
    }

    private function stockOutlook(User $user, string $code, ?Facility $facility): array
    {
        if (! $user->can('inventory.view')) {
            return $this->denied('inventory.view');
        }

        $item = Item::query()->where('code', $code)->first() ?? Item::query()->search($code)->first();

        if ($item === null) {
            return ['error' => "No material matches \"{$code}\". Try find_items."];
        }

        $outlook = $this->outlook->forItem($item, $facility);

        return [...$outlook->toArray(), 'sentences' => $outlook->sentences()];
    }

    private function reorderAdvice(User $user, ?Facility $facility): array
    {
        if (! $user->can('inventory.view') && ! $user->can('purchase.view')) {
            return $this->denied('purchase.view');
        }

        return [
            'as_of' => CarbonImmutable::now()->toDateString(),
            'items' => $this->outlook->reorderAdvice($facility)->take(20)->map(fn (ItemOutlook $o) => [
                ...collect($o->toArray())->only(['code', 'name', 'unit', 'on_hand', 'usable', 'upcoming_requirement', 'shortfall', 'recommended_quantity', 'order_by', 'runs_out_at', 'status', 'lead_time_days', 'vendor'])->all(),
                'sentence' => implode(' ', $o->sentences()),
            ])->values()->all(),
        ];
    }

    private function factoryExceptions(User $user, ?Facility $facility): array
    {
        if (! $user->can('report.view')) {
            return $this->denied('report.view');
        }

        $asOf = CarbonImmutable::now();

        return ['exceptions' => $this->exceptions->detect($facility, $asOf)->map(fn (FactoryException $e) => $e->toArray($asOf))->values()->all()];
    }

    private function commandCentreSnapshot(User $user, ?Facility $facility): array
    {
        if (! $user->can('report.view')) {
            return $this->denied('report.view');
        }

        $snapshot = $this->commandCentre->snapshot($facility);
        unset($snapshot['exceptions']);

        return $snapshot;
    }

    private function orderStatus(User $user, string $number): array
    {
        if (! $user->can('production.view')) {
            return $this->denied('production.view');
        }

        $order = ManufacturingOrder::query()->where('number', trim($number))->with(['product:id,code,name', 'client:id,name', 'facility:id,name', 'plannedUom:id,code', 'lines.item:id,code,name', 'lines.uom:id,code'])->first();

        if ($order === null) {
            return ['error' => "No manufacturing order is numbered \"{$number}\"."];
        }

        if (! $this->access->isCompanyWide($user) && ! in_array($order->facility_id, $this->access->facilityIds($user) ?? [], true)) {
            return ['error' => 'That order is at a facility you are not assigned to.'];
        }

        $stages = $this->stages->summary($order);

        return [
            'number' => $order->number,
            'status' => $order->status->value,
            'product' => $order->product?->name,
            'client' => $order->client?->name,
            'facility' => $order->facility?->name,
            'planned' => Decimal::strip((string) $order->planned_quantity).' '.($order->plannedUom?->code ?? ''),
            'planned_units' => $order->planned_units,
            'output_units' => $order->output_units,
            'yield_percent' => $order->yield_percentage,
            'required_delivery_at' => $order->required_delivery_at,
            'approved_at' => $order->approved_at?->toIso8601String(),
            'started_at' => $order->started_at?->toIso8601String(),
            'completed_at' => $order->completed_at?->toIso8601String(),
            'stage' => $stages['stage_label'] ?? null,
            'progress_percent' => $stages['progress'] ?? 0,
            'stage_history' => array_slice($stages['events'] ?? [], -8),
            'lines' => $order->lines->map(fn ($l) => [
                'item' => $l->item?->name, 'code' => $l->item?->code, 'kind' => $l->store_kind->value,
                'planned' => Decimal::strip((string) $l->planned_quantity), 'reserved' => Decimal::strip((string) $l->reserved_quantity), 'consumed' => Decimal::strip((string) $l->consumed_quantity), 'unit' => $l->uom?->code,
            ])->all(),
            'scan_verification' => collect($this->verification->status($order))->only(['verified', 'required', 'complete'])->all(),
        ];
    }

    private function traceBatch(User $user, string $batch): array
    {
        if (! $user->can('inventory.view')) {
            return $this->denied('inventory.view');
        }

        $lot = InventoryLot::query()->where('batch_number', trim($batch))->first();

        if ($lot === null) {
            return ['error' => "No batch is numbered \"{$batch}\"."];
        }

        $trace = $this->trace->trace($lot);

        return [
            'origin' => $trace['origin'],
            'summary' => $trace['summary'],
            'orders' => array_slice($trace['orders'], 0, 20),
            'affected' => array_map(fn (array $r) => collect($r)->only(['lot', 'item', 'depth', 'via', 'qc_status', 'client', 'on_hand', 'where', 'transfers', 'dispatched'])->all(), array_slice($trace['affected'], 0, 30)),
            'made_from' => array_slice($trace['backward'], 0, 30),
        ];
    }

    private function scorecardsFor(User $user, int $days, ?Facility $facility): array
    {
        if (! $user->can('report.view')) {
            return $this->denied('report.view');
        }

        return $this->scorecards->build($facility, in_array($days, [7, 30, 90, 365], true) ? $days : 30);
    }

    private function stockRisks(User $user, ?Facility $facility): array
    {
        if (! $user->can('inventory.view')) {
            return $this->denied('inventory.view');
        }

        $slow = $this->slowMoving->report($facility);
        $expiry = $this->expiryRisk->report($facility);

        return [
            'slow_moving' => ['buckets' => $slow['buckets'], 'total_value' => $slow['total_value'], 'top' => array_slice($slow['rows'], 0, 15)],
            'expiry_risk' => [...collect($expiry)->except('rows')->all(), 'top' => array_slice($expiry['rows'], 0, 15)],
        ];
    }

    private function clientProfitability(User $user, int $days): array
    {
        if (! $user->can('costing.view')) {
            return $this->denied('costing.view');
        }

        return $this->profitability->byClient(max(7, min(730, $days)));
    }

    // -- Helpers ---------------------------------------------------------------

    private function facility(User $user, array $input): ?Facility
    {
        $id = (int) ($input['facility_id'] ?? 0);

        if ($id <= 0) {
            // Someone assigned to one plant sees that plant.
            $ids = $this->access->isCompanyWide($user) ? null : ($this->access->facilityIds($user) ?? []);

            return $ids !== null && count($ids) === 1 ? Facility::query()->find($ids[0]) : null;
        }

        return $this->access->facilitiesFor($user)->firstWhere('id', $id);
    }

    private function denied(string $permission): array
    {
        return ['error' => "You do not hold {$permission}, so the assistant cannot show this. Tell the person which permission it needs."];
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>  $required
     */
    private function tool(string $name, string $description, array $properties, array $required = []): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'inputSchema' => ['type' => 'object', 'properties' => $properties === [] ? new \stdClass : $properties, 'required' => $required],
        ];
    }
}
