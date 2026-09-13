<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Services;

use App\Domain\Approvals\Models\Approval;
use App\Domain\Intelligence\DTOs\FactoryException;
use App\Domain\Intelligence\DTOs\ItemOutlook;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Manufacturing\Enums\ManufacturingOrderStatus;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Manufacturing\Services\ProductionStageService;
use App\Domain\Planning\Enums\MaterialRequestStatus;
use App\Domain\Planning\Enums\ProductionPlanStatus;
use App\Domain\Planning\Models\MaterialRequestLine;
use App\Domain\Planning\Models\ProductionPlan;
use App\Domain\Planning\Models\ProductionPlanLine;
use App\Domain\Quality\Models\QcInspection;
use App\Domain\Warehousing\Models\Facility;
use App\Support\Math\Decimal;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One screen for management that answers: what is running, what is
 * delayed, what is waiting for QC, what is short, what is dispatching
 * today, what requires approval, and what could stop production tomorrow.
 *
 * Each question is one method so the screen can refresh a section on its
 * own. Nothing here decides anything; it reads what the floor recorded.
 */
class CommandCentreService
{
    public function __construct(
        private readonly StockOutlookService $outlook,
        private readonly ExceptionService $exceptions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(?Facility $facility = null, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();
        $exceptions = $this->exceptions->detect($facility, $asOf);
        $outlooks = $this->outlook->reorderAdvice($facility, asOf: $asOf);

        return [
            'as_of' => $asOf->toIso8601String(),
            'running' => $this->running($facility, $asOf),
            'delayed' => $this->delayed($exceptions),
            'awaiting_qc' => $this->awaitingQc($facility, $asOf),
            'short' => $this->short($facility),
            'dispatching' => $this->dispatching($facility, $asOf),
            'approvals' => $this->approvals($facility),
            'tomorrow' => $this->tomorrow($outlooks, $facility, $asOf),
            'exceptions' => $exceptions->map(fn (FactoryException $e) => $e->toArray($asOf))->values()->all(),
            'counts' => [
                'exceptions_high' => $exceptions->where('severity', FactoryException::HIGH)->count(),
                'exceptions' => $exceptions->count(),
                'order_today' => $outlooks->where('status', ItemOutlook::ORDER_TODAY)->count(),
            ],
        ];
    }

    /**
     * Batches on the floor and batches ready to start.
     *
     * @return list<array<string, mixed>>
     */
    public function running(?Facility $facility, CarbonImmutable $asOf): array
    {
        $hours = (int) config('erp.exceptions.batch_running_hours', 48);

        return ManufacturingOrder::query()
            ->whereIn('status', [ManufacturingOrderStatus::InProgress->value, ManufacturingOrderStatus::Approved->value])
            ->when($facility, fn ($q) => $q->where('facility_id', $facility->id))
            ->with(['product:id,name', 'client:id,name', 'plannedUom:id,code', 'facility:id,code'])
            ->orderByRaw("case status when 'in_progress' then 0 else 1 end")
            ->orderBy('started_at')
            ->get()
            ->map(function (ManufacturingOrder $o) use ($asOf, $hours): array {
                $elapsed = $o->started_at ? round($o->started_at->diffInMinutes($asOf, true) / 60, 1) : null;

                return [
                    'id' => $o->id,
                    'number' => $o->number,
                    'product' => $o->product?->name,
                    'client' => $o->client?->name,
                    'facility' => $o->facility?->code,
                    'batch' => Decimal::strip($o->planned_quantity).' '.($o->plannedUom?->code ?? '').($o->planned_units ? " · {$o->planned_units} units" : ''),
                    'status' => $o->status->value,
                    'status_label' => $o->status->label(),
                    'stage' => ProductionStageService::phrase($o),
                    'progress' => $o->current_stage === null ? null : $o->current_stage->overallProgress((int) $o->stage_progress),
                    'started_at' => $o->started_at?->toIso8601String(),
                    'elapsed_hours' => $elapsed,
                    'late' => $elapsed !== null && $elapsed >= $hours,
                    'required_delivery_at' => $o->required_delivery_at?->toDateString(),
                    'href' => route('manufacturing.show', $o),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Everything that is later than it should be, from the exception feed.
     *
     * @param  Collection<int, FactoryException>  $exceptions
     * @return list<array<string, mixed>>
     */
    public function delayed(Collection $exceptions): array
    {
        return $exceptions
            ->filter(fn (FactoryException $e) => in_array($e->rule, ['delayed_batch', 'overdue_plan', 'pmr_overdue', 'late_transfer'], true))
            ->map(fn (FactoryException $e) => $e->toArray())
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function awaitingQc(?Facility $facility, CarbonImmutable $asOf): array
    {
        $slow = (int) config('erp.exceptions.qc_pending_hours', 6);
        $storeIds = $facility?->stores()->pluck('id')->all();

        return QcInspection::query()
            ->open()
            ->when($storeIds !== null, fn ($q) => $q->whereIn('destination_warehouse_id', $storeIds))
            ->with(['item:id,name,stock_uom_id', 'item.stockUom:id,code', 'lot:id,batch_number,owner_client_id', 'lot.ownerClient:id,name'])
            ->orderBy('created_at')
            ->get()
            ->map(function (QcInspection $i) use ($asOf, $slow): array {
                $hours = round($i->created_at->diffInMinutes($asOf, true) / 60, 1);

                return [
                    'id' => $i->id,
                    'number' => $i->number,
                    'item' => $i->item?->name,
                    'batch' => $i->lot?->batch_number,
                    'client' => $i->lot?->ownerClient?->name,
                    'quantity' => Decimal::strip($i->quantity).' '.($i->item?->stockUom?->code ?? ''),
                    'status' => $i->status->value,
                    'waiting_hours' => $hours,
                    'slow' => $hours >= $slow,
                    'href' => route('qc.show', $i),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Materials short on open plans and unfilled on material requests,
     * grouped by material.
     *
     * @return list<array<string, mixed>>
     */
    public function short(?Facility $facility): array
    {
        $storeIds = $facility?->stores()->pluck('id')->all();

        $planShort = ProductionPlanLine::query()
            ->where('shortage_quantity', '>', 0)
            ->whereHas('plan', fn ($q) => $q
                ->whereIn('status', [ProductionPlanStatus::Checked->value, ProductionPlanStatus::Requested->value])
                ->when($facility, fn ($q) => $q->where('facility_id', $facility->id)))
            ->with(['plan:id,number,planned_start_date,client_id', 'plan.client:id,name', 'item:id,code,name,stock_uom_id', 'item.stockUom:id,code'])
            ->get();

        $requested = MaterialRequestLine::query()
            ->whereColumn('received_quantity', '<', 'quantity_to_order')
            ->whereHas('request', fn ($q) => $q
                ->whereIn('status', [MaterialRequestStatus::Open->value, MaterialRequestStatus::PartiallyReceived->value])
                ->when($storeIds !== null, fn ($q) => $q->whereIn('warehouse_id', $storeIds)))
            ->with('request:id,number,needed_by')
            ->get()
            ->groupBy('item_id');

        return $planShort
            ->groupBy('item_id')
            ->map(function (Collection $lines) use ($requested): array {
                $item = $lines->first()->item;
                $shortage = $lines->reduce(fn (BigDecimal $c, ProductionPlanLine $l) => $c->plus(BigDecimal::of($l->shortage_quantity)), BigDecimal::zero());
                $onRequest = ($requested->get($item->id) ?? collect())->reduce(fn (BigDecimal $c, MaterialRequestLine $l) => $c->plus(BigDecimal::of($l->quantity_to_order)->minus(BigDecimal::of($l->received_quantity))), BigDecimal::zero());

                return [
                    'item_id' => $item->id,
                    'code' => $item->code,
                    'name' => $item->name,
                    'unit' => $item->stockUom?->code,
                    'shortage' => Decimal::strip($shortage),
                    'requested' => Decimal::strip($onRequest),
                    'client_material' => $lines->contains(fn (ProductionPlanLine $l) => $l->source === 'client'),
                    'plans' => $lines->map(fn (ProductionPlanLine $l) => [
                        'id' => $l->plan->id,
                        'number' => $l->plan->number,
                        'start' => $l->plan->planned_start_date,
                        'client' => $l->plan->client?->name,
                        'shortage' => Decimal::strip($l->shortage_quantity),
                    ])->values()->all(),
                    'requests' => ($requested->get($item->id) ?? collect())->map(fn (MaterialRequestLine $l) => [
                        'id' => $l->request->id,
                        'number' => $l->request->number,
                        'needed_by' => $l->request->needed_by?->toDateString(),
                    ])->values()->all(),
                ];
            })
            ->sortByDesc(fn (array $r) => (float) $r['shortage'])
            ->values()
            ->all();
    }

    /**
     * What should leave today: client batches due, finished goods of
     * clients waiting to go, and batches completed today.
     *
     * @return array{due: list<array<string, mixed>>, awaiting_dispatch: list<array<string, mixed>>}
     */
    public function dispatching(?Facility $facility, CarbonImmutable $asOf): array
    {
        $today = $asOf->toDateString();
        $storeIds = $facility?->stores()->pluck('id')->all();

        $due = ManufacturingOrder::query()
            ->whereNotNull('required_delivery_at')
            ->where('required_delivery_at', '<=', $today)
            ->whereIn('status', [ManufacturingOrderStatus::Approved->value, ManufacturingOrderStatus::InProgress->value, ManufacturingOrderStatus::Completed->value])
            ->where(fn ($q) => $q->where('status', '<>', ManufacturingOrderStatus::Completed->value)->orWhere('completed_at', '>=', $asOf->subDays(7)))
            ->when($facility, fn ($q) => $q->where('facility_id', $facility->id))
            ->with(['product:id,name', 'client:id,name', 'outputLot:id,batch_number,qc_status'])
            ->orderBy('required_delivery_at')
            ->get()
            ->map(fn (ManufacturingOrder $o) => [
                'id' => $o->id,
                'number' => $o->number,
                'product' => $o->product?->name,
                'client' => $o->client?->name,
                'client_po_ref' => $o->client_po_ref,
                'required_delivery_at' => $o->required_delivery_at?->toDateString(),
                'overdue' => $o->required_delivery_at?->lessThan($asOf->startOfDay()) ?? false,
                'status' => $o->status->value,
                'status_label' => $o->status->label(),
                'ready' => $o->status === ManufacturingOrderStatus::Completed && in_array($o->outputLot?->qc_status, [LotQcStatus::Approved, LotQcStatus::NotRequired], true),
                'href' => route('manufacturing.show', $o),
            ])
            ->values()
            ->all();

        $awaiting = InventoryLot::query()
            ->whereNotNull('owner_client_id')
            ->whereIn('qc_status', [LotQcStatus::Approved->value, LotQcStatus::NotRequired->value])
            ->whereHas('item', fn ($q) => $q->where('type', 'finished_good'))
            ->with(['ownerClient:id,name', 'item:id,name'])
            ->withSum(['balances as on_hand' => fn ($q) => $q->when($storeIds !== null, fn ($q) => $q->whereIn('warehouse_id', $storeIds))], 'on_hand')
            ->orderBy('manufactured_at')
            ->get()
            ->filter(fn (InventoryLot $lot) => (float) ($lot->on_hand ?? 0) > 0)
            ->map(fn (InventoryLot $lot) => [
                'lot_id' => $lot->id,
                'batch_number' => $lot->batch_number,
                'client' => $lot->ownerClient?->name,
                'product' => $lot->item?->name,
                'on_hand' => Decimal::strip((string) $lot->on_hand),
                'manufactured_at' => $lot->manufactured_at?->toDateString(),
                'href' => route('lots.show', $lot),
            ])
            ->values()
            ->all();

        return ['due' => $due, 'awaiting_dispatch' => $awaiting];
    }

    /**
     * What is waiting on someone's signature.
     *
     * @return list<array<string, mixed>>
     */
    public function approvals(?Facility $facility): array
    {
        $rows = [];

        foreach (Approval::query()->where('status', 'pending')->with('requestedBy:id,name')->orderBy('requested_at')->get() as $approval) {
            $rows[] = [
                'kind' => 'approval',
                'label' => str_replace('_', ' ', ucfirst((string) $approval->workflow_key)),
                'number' => class_basename((string) $approval->approvable_type).' #'.$approval->approvable_id,
                'who' => $approval->requestedBy?->name,
                'since' => $approval->requested_at?->toIso8601String(),
                'href' => null,
            ];
        }

        foreach (StockTransfer::query()
            ->where('status', StockTransferStatus::Requested->value)
            ->when($facility, fn ($q) => $q->where(fn ($q) => $q->where('source_facility_id', $facility->id)->orWhere('destination_facility_id', $facility->id)))
            ->with(['requester:id,name', 'destinationFacility:id,name'])
            ->orderBy('requested_at')
            ->get() as $transfer) {
            $rows[] = [
                'kind' => 'transfer',
                'label' => 'Stock transfer to '.($transfer->destinationFacility?->name ?? '—'),
                'number' => $transfer->number,
                'who' => $transfer->requester?->name,
                'since' => $transfer->requested_at?->toIso8601String(),
                'href' => route('transfers.show', $transfer),
            ];
        }

        foreach (QcInspection::query()->where('status', LotQcStatus::OnHold->value)->with('item:id,name')->orderBy('updated_at')->get() as $inspection) {
            $rows[] = [
                'kind' => 'qc_hold',
                'label' => 'QC on hold · '.($inspection->item?->name ?? ''),
                'number' => $inspection->number,
                'who' => null,
                'since' => $inspection->updated_at?->toIso8601String(),
                'href' => route('qc.show', $inspection),
            ];
        }

        foreach (ProductionPlan::query()
            ->where('status', ProductionPlanStatus::Checked->value)
            ->whereDoesntHave('lines', fn ($q) => $q->where('shortage_quantity', '>', 0))
            ->when($facility, fn ($q) => $q->where('facility_id', $facility->id))
            ->with(['product:id,name', 'createdBy:id,name'])
            ->orderBy('planned_start_date')
            ->get() as $plan) {
            $rows[] = [
                'kind' => 'plan',
                'label' => 'Plan ready to release · '.($plan->product?->name ?? ''),
                'number' => $plan->number,
                'who' => $plan->createdBy?->name,
                'since' => $plan->checked_at?->toIso8601String(),
                'href' => route('plans.show', $plan),
            ];
        }

        return $rows;
    }

    /**
     * What could stop production tomorrow: materials that will not cover
     * what is needed by then, and batches ready to start whose materials
     * are still in quarantine.
     *
     * @param  Collection<int, ItemOutlook>  $outlooks
     * @return list<array<string, mixed>>
     */
    public function tomorrow(Collection $outlooks, ?Facility $facility, CarbonImmutable $asOf): array
    {
        $limit = $asOf->addDay()->endOfDay();
        $rows = [];

        foreach ($outlooks as $o) {
            $deadline = collect([$o->neededBy, $o->runsOutAt])->filter()->min();

            if (! $deadline instanceof CarbonImmutable || $deadline->greaterThan($limit) || ! $o->shortfall->isPositive()) {
                continue;
            }

            $rows[] = [
                'kind' => 'material',
                'title' => "{$o->name} short by ".Decimal::strip($o->shortfall)." {$o->unit}",
                'detail' => Decimal::strip($o->usable)." {$o->unit} usable against ".Decimal::strip($o->upcomingRequirement)." {$o->unit} needed by ".$deadline->format('j M').'. Lead time '.$o->leadTimeDays.' days: a delivery cannot land in time; borrow, substitute or reschedule.',
                'href' => route('purchase.reorder-advice'),
                'severity' => FactoryException::HIGH,
            ];
        }

        $blocked = ManufacturingOrder::query()
            ->where('status', ManufacturingOrderStatus::Approved->value)
            ->when($facility, fn ($q) => $q->where('facility_id', $facility->id))
            ->with(['product:id,name', 'reservations' => fn ($q) => $q->where('status', 'active'), 'reservations.lot:id,batch_number,qc_status'])
            ->get()
            ->filter(fn (ManufacturingOrder $o) => $o->reservations->contains(fn ($r) => $r->lot !== null && $r->lot->qc_status === LotQcStatus::Pending));

        foreach ($blocked as $o) {
            $lots = $o->reservations->filter(fn ($r) => $r->lot?->qc_status === LotQcStatus::Pending)->map(fn ($r) => $r->lot->batch_number)->unique()->implode(', ');
            $rows[] = [
                'kind' => 'batch',
                'title' => "{$o->number} cannot start until QC releases {$lots}",
                'detail' => ($o->product?->name ?? 'The batch').' is approved and reserved against stock still in quarantine.',
                'href' => route('manufacturing.show', $o),
                'severity' => FactoryException::MEDIUM,
            ];
        }

        return $rows;
    }
}
