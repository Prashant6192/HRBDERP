<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Enums\StockAlertLevel;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Services\StockAlertService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\Manufacturing\Enums\ManufacturingOrderStatus;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Planning\Enums\ProductionPlanStatus;
use App\Domain\Planning\Models\MaterialRequest;
use App\Domain\Planning\Models\MaterialRequestLine;
use App\Domain\Planning\Models\ProductionPlan;
use App\Domain\Procurement\Models\GoodsReceiptLine;
use App\Domain\Quality\Models\QcInspection;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Services\WarehouseResolver;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The figures behind the dashboard.
 *
 * Every method answers one card. The controller decides which cards a user
 * may see; nothing here is computed for a card that will not be shown.
 */
class DashboardService
{
    public function __construct(
        private readonly StockBalanceService $balances,
        private readonly StockAlertService $alerts,
        private readonly WarehouseResolver $warehouses,
    ) {}

    /**
     * @return list<array{key: string, label: string, value: int, hint: string|null, icon: string, href: string|null, tone: string}>
     */
    public function kpis(User $user): array
    {
        $tiles = [];

        if ($user->can('production.view')) {
            $running = ManufacturingOrder::query()->where('status', ManufacturingOrderStatus::InProgress->value)->count();
            $approved = ManufacturingOrder::query()->where('status', ManufacturingOrderStatus::Approved->value)->count();

            $tiles[] = [
                'key' => 'in_production',
                'label' => 'In production',
                'value' => $running + $approved,
                'hint' => "{$running} running · {$approved} ready to start",
                'icon' => 'factory',
                'href' => route('manufacturing.index'),
                'tone' => 'default',
            ];
        }

        if ($user->can('planning.view')) {
            $open = ProductionPlan::query()->whereIn('status', [ProductionPlanStatus::Checked->value, ProductionPlanStatus::Requested->value])->count();
            $short = ProductionPlan::query()
                ->whereIn('status', [ProductionPlanStatus::Checked->value, ProductionPlanStatus::Requested->value])
                ->whereHas('lines', fn ($q) => $q->where('shortage_quantity', '>', 0))
                ->count();

            $tiles[] = [
                'key' => 'open_plans',
                'label' => 'Plans awaiting production',
                'value' => $open,
                'hint' => $short > 0 ? "{$short} with materials short" : 'all materials available',
                'icon' => 'calendar-check',
                'href' => route('plans.index'),
                'tone' => $short > 0 ? 'warning' : 'default',
            ];
        }

        if ($user->can('purchase.view')) {
            $open = MaterialRequest::query()->open()->count();
            $toOrder = MaterialRequestLine::query()
                ->whereHas('request', fn ($q) => $q->open())
                ->whereColumn('received_quantity', '<', 'quantity_to_order')
                ->count();

            $tiles[] = [
                'key' => 'material_requests',
                'label' => 'Open material requests',
                'value' => $open,
                'hint' => $toOrder > 0 ? "{$toOrder} line".($toOrder === 1 ? '' : 's').' still to buy' : 'nothing outstanding',
                'icon' => 'clipboard-pen',
                'href' => route('material-requests.index'),
                'tone' => $toOrder > 0 ? 'warning' : 'default',
            ];
        }

        if ($user->can('qc.view')) {
            $pending = QcInspection::query()->whereIn('status', [LotQcStatus::Pending->value, LotQcStatus::OnHold->value])->count();

            $tiles[] = [
                'key' => 'qc_pending',
                'label' => 'Awaiting QC',
                'value' => $pending,
                'hint' => $pending > 0 ? 'batches in quarantine' : 'quarantine is clear',
                'icon' => 'clipboard-check',
                'href' => route('qc.index'),
                'tone' => $pending > 0 ? 'warning' : 'success',
            ];
        }

        if ($user->can('inventory.view')) {
            $levels = $this->storeLevels();
            $critical = 0;

            foreach ($levels as $store) {
                $critical += $store['counts']['critical'] + $store['counts']['out_of_stock'];
            }

            $tiles[] = [
                'key' => 'critical',
                'label' => 'Critically low materials',
                'value' => $critical,
                'hint' => 'at or below minimum stock',
                'icon' => 'alert-triangle',
                'href' => route('stock.index', ['level' => 'critical']),
                'tone' => $critical > 0 ? 'danger' : 'success',
            ];

            $days = (int) config('erp.stock_alerts.expiry_warning_days', 90);
            $expiring = InventoryLot::query()->releasable()->expiringWithin($days)
                ->whereHas('balances', fn ($q) => $q->where('on_hand', '>', 0))
                ->count();

            $tiles[] = [
                'key' => 'expiring',
                'label' => "Expiring within {$days} days",
                'value' => $expiring,
                'hint' => 'batches still in stock',
                'icon' => 'calendar-clock',
                'href' => route('lots.index'),
                'tone' => $expiring > 0 ? 'warning' : 'success',
            ];
        }

        return $tiles;
    }

    /**
     * Each store's items by alert level.
     *
     * @return list<array{kind: string, label: string, code: string|null, warehouse_id: int|null, items: int, counts: array<string, int>, href: string}>
     */
    public function storeLevels(): array
    {
        $result = [];

        foreach ([WarehouseType::RawMaterial, WarehouseType::Packaging, WarehouseType::FinishedGoods] as $type) {
            $warehouse = $this->warehouses->findStoreOfType($type);
            $counts = array_fill_keys(array_map(fn (StockAlertLevel $l) => $l->value, StockAlertLevel::cases()), 0);
            $items = 0;

            if ($warehouse !== null) {
                foreach ($this->balances->summaryForWarehouse($warehouse) as $row) {
                    $counts[$this->alerts->levelFor($row['item'], $row['on_hand'])->value]++;
                    $items++;
                }
            }

            $result[] = [
                'kind' => $type->value,
                'label' => $type->label(),
                'code' => $warehouse?->code,
                'warehouse_id' => $warehouse?->id,
                'items' => $items,
                'counts' => $counts,
                'href' => route('stock.index', ['store' => $type->value]),
            ];
        }

        return $result;
    }

    /**
     * Materials that need attention across the raw material and packaging
     * stores, worst first.
     *
     * @return list<array{item_id: int, code: string, name: string, type: string, store: string, on_hand: string, uom: string|null, level: string}>
     */
    public function attention(int $limit = 8): array
    {
        $rows = collect();

        foreach ([WarehouseType::RawMaterial, WarehouseType::Packaging] as $type) {
            $warehouse = $this->warehouses->findStoreOfType($type);

            if ($warehouse === null) {
                continue;
            }

            foreach ($this->balances->summaryForWarehouse($warehouse) as $row) {
                $level = $this->alerts->levelFor($row['item'], $row['on_hand']);

                if (! $level->needsAttention()) {
                    continue;
                }

                $rows->push([
                    'item_id' => $row['item']->id,
                    'code' => $row['item']->code,
                    'name' => $row['item']->name,
                    'type' => $row['item']->type->value,
                    'store' => $warehouse->code,
                    'on_hand' => (string) $row['on_hand'],
                    'uom' => $row['item']->stockUom?->code,
                    'level' => $level->value,
                    'severity' => $level->severity(),
                ]);
            }
        }

        return $rows->sortBy([['severity', 'desc'], ['name', 'asc']])->take($limit)->map(fn (array $r) => collect($r)->except('severity')->all())->values()->all();
    }

    /**
     * @return list<array{lot_id: int, batch_number: string, item: string, code: string, expiry_at: string, days: int, on_hand: string, uom: string|null}>
     */
    public function expiring(int $limit = 6): array
    {
        $days = (int) config('erp.stock_alerts.expiry_warning_days', 90);

        return InventoryLot::query()
            ->releasable()
            ->expiringWithin($days)
            ->with(['item:id,code,name,stock_uom_id', 'item.stockUom:id,code'])
            ->whereHas('balances', fn ($q) => $q->where('on_hand', '>', 0))
            ->withSum('balances', 'on_hand')
            ->orderBy('expiry_at')
            ->limit($limit)
            ->get()
            ->map(fn (InventoryLot $lot): array => [
                'lot_id' => $lot->id,
                'batch_number' => $lot->batch_number,
                'item' => $lot->item->name,
                'code' => $lot->item->code,
                'expiry_at' => $lot->expiry_at->toDateString(),
                'days' => (int) now()->startOfDay()->diffInDays($lot->expiry_at, false),
                'on_hand' => (string) $lot->balances_sum_on_hand,
                'uom' => $lot->item->stockUom?->code,
            ])
            ->all();
    }

    /**
     * Deliveries booked in and QC decisions, per day.
     *
     * @return list<array{date: string, received: int, approved: int, rejected: int}>
     */
    public function receiving(int $days): array
    {
        $start = CarbonImmutable::today()->subDays($days - 1);

        $received = GoodsReceiptLine::query()
            ->join('goods_receipts', 'goods_receipts.id', '=', 'goods_receipt_lines.goods_receipt_id')
            ->where('goods_receipts.status', 'received')
            ->where('goods_receipts.posted_at', '>=', $start)
            ->selectRaw('date(goods_receipts.posted_at) as day, count(*) as n')
            ->groupBy('day')
            ->pluck('n', 'day');

        $decided = QcInspection::query()
            ->whereNotNull('decided_at')
            ->where('decided_at', '>=', $start)
            ->selectRaw('date(decided_at) as day, status, count(*) as n')
            ->groupBy('day', 'status')
            ->get();

        $series = [];

        for ($i = 0; $i < $days; $i++) {
            $day = $start->addDays($i)->toDateString();
            $series[] = [
                'date' => $day,
                'received' => (int) ($received[$day] ?? 0),
                'approved' => (int) $decided->where('day', $day)->where('status', LotQcStatus::Approved->value)->sum('n'),
                'rejected' => (int) $decided->where('day', $day)->where('status', LotQcStatus::Rejected->value)->sum('n'),
            ];
        }

        return $series;
    }

    /**
     * Finished units per week from completed orders.
     *
     * @return list<array{week: string, start: string, units: int, batches: int}>
     */
    public function output(int $weeks = 12): array
    {
        $start = CarbonImmutable::today()->startOfWeek()->subWeeks($weeks - 1);

        $rows = ManufacturingOrder::query()
            ->where('status', ManufacturingOrderStatus::Completed->value)
            ->where('completed_at', '>=', $start)
            ->selectRaw("date_trunc('week', completed_at)::date as week_start, coalesce(sum(output_units), 0) as units, count(*) as batches")
            ->groupBy('week_start')
            ->get()
            ->keyBy(fn ($r) => CarbonImmutable::parse($r->week_start)->toDateString());

        $series = [];

        for ($i = 0; $i < $weeks; $i++) {
            $week = $start->addWeeks($i);
            $row = $rows->get($week->toDateString());
            $series[] = [
                'week' => 'W'.$week->isoWeek,
                'start' => $week->toDateString(),
                'units' => (int) ($row->units ?? 0),
                'batches' => (int) ($row->batches ?? 0),
            ];
        }

        return $series;
    }

    /**
     * @return list<array{id: int, number: string, product: string|null, formula: string|null, batch: string, status: string, started_at: string|null, approved_at: string|null, stage: int}>
     */
    public function inProduction(int $limit = 6): array
    {
        return ManufacturingOrder::query()
            ->whereIn('status', [ManufacturingOrderStatus::Approved->value, ManufacturingOrderStatus::InProgress->value])
            ->with(['product:id,name', 'formula:id,name', 'plannedUom:id,code'])
            ->orderByRaw("case status when 'in_progress' then 0 else 1 end")
            ->orderByDesc('approved_at')
            ->limit($limit)
            ->get()
            ->map(fn (ManufacturingOrder $o): array => [
                'id' => $o->id,
                'number' => $o->number,
                'product' => $o->product?->name,
                'formula' => $o->formula?->name,
                'batch' => rtrim(rtrim($o->planned_quantity, '0'), '.').' '.$o->plannedUom->code.($o->planned_units ? " · {$o->planned_units} units" : ''),
                'status' => $o->status->value,
                'started_at' => $o->started_at?->toIso8601String(),
                'approved_at' => $o->approved_at?->toIso8601String(),
                'stage' => $o->status === ManufacturingOrderStatus::InProgress ? 2 : 1,
            ])
            ->all();
    }

    /**
     * Dates that matter in the next fortnight: plans starting, requests due.
     *
     * @return list<array{date: string, kind: string, label: string, href: string}>
     */
    public function upcoming(User $user, int $days = 14): array
    {
        $from = CarbonImmutable::today();
        $to = $from->addDays($days);
        $rows = collect();

        if ($user->can('planning.view')) {
            ProductionPlan::query()
                ->whereIn('status', [ProductionPlanStatus::Checked->value, ProductionPlanStatus::Requested->value, ProductionPlanStatus::InProduction->value])
                ->whereBetween('planned_start_date', [$from, $to])
                ->with('formula:id,name')
                ->orderBy('planned_start_date')
                ->get()
                ->each(fn (ProductionPlan $p) => $rows->push([
                    'date' => $p->planned_start_date->toDateString(),
                    'kind' => 'plan',
                    'label' => "{$p->number} · {$p->formula?->name} starts",
                    'href' => route('plans.show', $p),
                ]));
        }

        if ($user->can('purchase.view')) {
            MaterialRequest::query()
                ->open()
                ->whereBetween('needed_by', [$from, $to])
                ->orderBy('needed_by')
                ->get()
                ->each(fn (MaterialRequest $r) => $rows->push([
                    'date' => $r->needed_by->toDateString(),
                    'kind' => 'pmr',
                    'label' => "{$r->number} · {$r->store_kind->label()} needed",
                    'href' => route('material-requests.show', $r),
                ]));
        }

        return $rows->sortBy('date')->take(8)->values()->all();
    }

    /**
     * Headline sentences for the greeting card.
     *
     * @param  list<array{key: string, value: int, hint: string|null}>  $kpis
     * @return list<string>
     */
    public function headlines(array $kpis): array
    {
        $by = collect($kpis)->keyBy('key');
        $lines = [];

        if ($by->has('in_production')) {
            $n = $by['in_production']['value'];
            $lines[] = $n === 0 ? 'Nothing is in production right now.' : "{$n} batch".($n === 1 ? ' is' : 'es are').' in production.';
        }

        if ($by->has('critical')) {
            $n = $by['critical']['value'];
            $lines[] = $n === 0 ? 'No material is critically low.' : "{$n} material".($n === 1 ? ' is' : 's are').' critically low — check the stores.';
        }

        if ($by->has('qc_pending') && $by['qc_pending']['value'] > 0) {
            $n = $by['qc_pending']['value'];
            $lines[] = "{$n} batch".($n === 1 ? '' : 'es').' waiting for QC.';
        }

        if ($by->has('material_requests') && $by['material_requests']['value'] > 0) {
            $n = $by['material_requests']['value'];
            $lines[] = "{$n} material request".($n === 1 ? '' : 's').' open.';
        }

        return $lines;
    }
}
