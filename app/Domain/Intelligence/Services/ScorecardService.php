<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Services;

use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Enums\StockCountStatus;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Manufacturing\Enums\ManufacturingOrderStatus;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Manufacturing\Models\ManufacturingOrderAdjustment;
use App\Domain\Manufacturing\Models\ManufacturingOrderLine;
use App\Domain\MasterData\Enums\ItemType;
use App\Domain\Planning\Enums\MaterialRequestStatus;
use App\Domain\Planning\Enums\ProductionPlanStatus;
use App\Domain\Planning\Enums\StoreKind;
use App\Domain\Planning\Models\MaterialRequest;
use App\Domain\Planning\Models\ProductionPlan;
use App\Domain\Procurement\Enums\GoodsReceiptStatus;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Quality\Models\QcInspection;
use App\Domain\Warehousing\Models\Facility;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Department scorecards and process performance.
 *
 * Every figure is read from the documents the departments already keep:
 * plans, material requests, receipts, QC decisions, batches, transfers
 * and counts. Nothing here measures a person; it measures the process —
 * how much went through each step, how long it took, and what is waiting.
 * The dispatch figure is OTIF: client jobs completed on time and in full.
 */
class ScorecardService
{
    /**
     * @return array{period: array{days: int, from: string, to: string}, departments: list<array<string, mixed>>, processes: list<array<string, mixed>>, headline: array<string, mixed>}
     */
    public function build(?Facility $facility = null, int $days = 30, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();
        $from = $asOf->subDays($days);

        $departments = [
            $this->planning($facility, $from, $asOf),
            $this->purchase($facility, $from, $asOf),
            $this->store($facility, $from, $asOf),
            $this->qc($facility, $from, $asOf),
            $this->manufacturing($facility, $from, $asOf),
            $this->packaging($facility, $from, $asOf),
            $this->dispatch($facility, $from, $asOf),
        ];

        $scored = array_values(array_filter($departments, fn (array $d) => $d['score'] !== null));

        return [
            'period' => ['days' => $days, 'from' => $from->toDateString(), 'to' => $asOf->toDateString()],
            'departments' => $departments,
            'processes' => $this->processes($facility, $from, $asOf),
            'headline' => [
                'score' => $scored === [] ? null : (int) round(array_sum(array_column($scored, 'score')) / count($scored)),
                'otif_percent' => $departments[6]['kpis'][0]['value'],
                'inventory_accuracy_percent' => $departments[2]['kpis'][2]['value'],
                'yield_percent' => $departments[4]['kpis'][1]['value'],
                'qc_turnaround_hours' => $departments[3]['kpis'][1]['value'],
            ],
        ];
    }

    // -- Departments ---------------------------------------------------------

    private function planning(?Facility $facility, CarbonImmutable $from, CarbonImmutable $asOf): array
    {
        $plans = ProductionPlan::query()
            ->where('created_at', '>=', $from)
            ->when($facility, fn ($q) => $q->where('facility_id', $facility->id))
            ->get(['id', 'status', 'planned_start_date', 'created_at', 'checked_at']);

        $orders = ManufacturingOrder::query()
            ->whereNotNull('production_plan_id')
            ->whereNotNull('started_at')
            ->where('started_at', '>=', $from)
            ->when($facility, fn ($q) => $q->where('facility_id', $facility->id))
            ->with('plan:id,planned_start_date')
            ->get(['id', 'production_plan_id', 'started_at']);

        $converted = $plans->whereIn('status', [ProductionPlanStatus::InProduction, ProductionPlanStatus::Completed])->count();
        $dated = $orders->filter(fn (ManufacturingOrder $o) => $o->plan?->planned_start_date !== null);
        $onTime = $dated->filter(fn (ManufacturingOrder $o) => $o->started_at->startOfDay()->lte(CarbonImmutable::parse($o->plan->planned_start_date)->endOfDay()))->count();
        $notStarted = ProductionPlan::query()
            ->whereIn('status', [ProductionPlanStatus::Checked->value, ProductionPlanStatus::Requested->value])
            ->whereNotNull('planned_start_date')
            ->where('planned_start_date', '<', $asOf->toDateString())
            ->when($facility, fn ($q) => $q->where('facility_id', $facility->id))
            ->count();

        $adherence = $this->percent($onTime, $dated->count());

        return $this->department('planning', 'Planning', [
            $this->kpi('plans', 'Plans raised', (string) $plans->count(), null, 'neutral', 'in the period'),
            $this->kpi('converted', 'Plans in production', $this->percent($converted, $plans->count()), '%', $this->tone($this->percent($converted, $plans->count()), 60, 30), "{$converted} of {$plans->count()} checked plans became batches"),
            $this->kpi('start_adherence', 'Started on the planned date', $adherence, '%', $this->tone($adherence, 90, 70), "{$onTime} of {$dated->count()} batches with a planned start"),
            $this->kpi('not_started', 'Past planned start, not started', (string) $notStarted, null, $notStarted === 0 ? 'good' : ($notStarted <= 2 ? 'warn' : 'bad'), 'open plans whose date has passed'),
        ], [$adherence, $this->percent($converted, $plans->count())]);
    }

    private function purchase(?Facility $facility, CarbonImmutable $from, CarbonImmutable $asOf): array
    {
        $requests = MaterialRequest::query()
            ->where('requested_at', '>=', $from)
            ->when($facility, fn ($q) => $q->whereHas('plan', fn ($p) => $p->where('facility_id', $facility->id)))
            ->get(['id', 'status', 'needed_by', 'requested_at', 'fulfilled_at']);

        $fulfilled = $requests->where('status', MaterialRequestStatus::Fulfilled)->filter(fn (MaterialRequest $r) => $r->fulfilled_at !== null);
        $dated = $fulfilled->filter(fn (MaterialRequest $r) => $r->needed_by !== null);
        $onTime = $dated->filter(fn (MaterialRequest $r) => $r->fulfilled_at->startOfDay()->lte(CarbonImmutable::parse($r->needed_by)->endOfDay()))->count();
        $leadDays = $this->averageHours($fulfilled->map(fn (MaterialRequest $r) => [$r->requested_at, $r->fulfilled_at]));
        $overdue = MaterialRequest::query()
            ->whereIn('status', [MaterialRequestStatus::Open->value, MaterialRequestStatus::PartiallyReceived->value])
            ->whereNotNull('needed_by')
            ->where('needed_by', '<', $asOf->toDateString())
            ->when($facility, fn ($q) => $q->whereHas('plan', fn ($p) => $p->where('facility_id', $facility->id)))
            ->count();

        $adherence = $this->percent($onTime, $dated->count());

        return $this->department('purchase', 'Purchase', [
            $this->kpi('requests', 'Material requests raised', (string) $requests->count(), null, 'neutral', 'in the period'),
            $this->kpi('lead_time_adherence', 'Delivered by the need-by date', $adherence, '%', $this->tone($adherence, 90, 70), "{$onTime} of {$dated->count()} fulfilled requests"),
            $this->kpi('lead_time', 'Request to delivery', $leadDays === null ? null : number_format($leadDays / 24, 1, '.', ''), 'days', 'neutral', 'average, request raised to fully received'),
            $this->kpi('overdue', 'Open past need-by', (string) $overdue, null, $overdue === 0 ? 'good' : ($overdue <= 2 ? 'warn' : 'bad'), 'requests still open after their date'),
        ], [$adherence]);
    }

    private function store(?Facility $facility, CarbonImmutable $from, CarbonImmutable $asOf): array
    {
        $receipts = GoodsReceipt::query()
            ->where('status', GoodsReceiptStatus::Received->value)
            ->where('posted_at', '>=', $from)
            ->when($facility, fn ($q) => $q->whereHas('warehouse', fn ($w) => $w->where('facility_id', $facility->id)))
            ->get(['id', 'created_at', 'posted_at']);
        $bookingHours = $this->averageHours($receipts->map(fn (GoodsReceipt $r) => [$r->created_at, $r->posted_at]));

        $transfers = StockTransfer::query()
            ->whereIn('status', [StockTransferStatus::Received->value, StockTransferStatus::PartiallyReceived->value, StockTransferStatus::Discrepancy->value])
            ->whereNotNull('received_at')
            ->where('received_at', '>=', $from)
            ->when($facility, fn ($q) => $q->where(fn ($w) => $w->where('destination_facility_id', $facility->id)->orWhere('source_facility_id', $facility->id)))
            ->get(['id', 'status', 'expected_at', 'received_at']);
        $expected = $transfers->filter(fn (StockTransfer $t) => $t->expected_at !== null);
        $transfersOnTime = $expected->filter(fn (StockTransfer $t) => $t->received_at->lte(CarbonImmutable::parse($t->expected_at)->endOfDay()))->count();
        $transferAdherence = $this->percent($transfersOnTime, $expected->count());

        $counts = StockCount::query()
            ->where('status', StockCountStatus::Approved->value)
            ->where('approved_at', '>=', $from)
            ->when($facility, fn ($q) => $q->whereHas('warehouse', fn ($w) => $w->where('facility_id', $facility->id)))
            ->with('lines:id,stock_count_id,system_quantity,counted_quantity')
            ->get(['id']);
        $countLines = $counts->flatMap(fn (StockCount $c) => $c->lines)->filter(fn ($l) => $l->counted_quantity !== null);
        $accurate = $countLines->filter(fn ($l) => BigDecimal::of($l->counted_quantity)->isEqualTo(BigDecimal::of($l->system_quantity)))->count();
        $accuracy = $this->percent($accurate, $countLines->count());

        return $this->department('store', 'Stores', [
            $this->kpi('receipts', 'Deliveries booked', (string) $receipts->count(), null, 'neutral', 'posted in the period'),
            $this->kpi('booking_time', 'Bill to booked', $bookingHours === null ? null : number_format($bookingHours, 1, '.', ''), 'hours', $bookingHours === null ? 'neutral' : ($bookingHours <= 24 ? 'good' : ($bookingHours <= 72 ? 'warn' : 'bad')), 'average, receipt opened to posted'),
            $this->kpi('inventory_accuracy', 'Inventory accuracy', $accuracy, '%', $this->tone($accuracy, 98, 90), $counts->count().' approved count'.($counts->count() === 1 ? '' : 's').", {$accurate} of {$countLines->count()} lines matched"),
            $this->kpi('transfers_on_time', 'Transfers received by the expected date', $transferAdherence, '%', $this->tone($transferAdherence, 90, 70), "{$transfersOnTime} of {$expected->count()} with an expected date"),
        ], [$accuracy, $transferAdherence]);
    }

    private function qc(?Facility $facility, CarbonImmutable $from, CarbonImmutable $asOf): array
    {
        $decided = QcInspection::query()
            ->whereNotNull('decided_at')
            ->where('decided_at', '>=', $from)
            ->when($facility, fn ($q) => $q->whereHas('destinationWarehouse', fn ($w) => $w->where('facility_id', $facility->id)))
            ->get(['id', 'status', 'created_at', 'decided_at']);
        $turnaround = $this->averageHours($decided->map(fn (QcInspection $i) => [$i->created_at, $i->decided_at]));
        $rejected = $decided->where('status', LotQcStatus::Rejected)->count();
        $rejection = $this->percent($rejected, $decided->count());
        $threshold = (int) config('erp.exceptions.qc_pending_hours', 6);
        $slow = QcInspection::query()
            ->where('status', LotQcStatus::Pending->value)
            ->where('created_at', '<', $asOf->subHours($threshold))
            ->when($facility, fn ($q) => $q->whereHas('destinationWarehouse', fn ($w) => $w->where('facility_id', $facility->id)))
            ->count();
        $rejectionLimit = (float) config('erp.exceptions.rejection_percent', 10);

        return $this->department('qc', 'Quality control', [
            $this->kpi('decisions', 'Decisions made', (string) $decided->count(), null, 'neutral', 'in the period'),
            $this->kpi('turnaround', 'Turnaround', $turnaround === null ? null : number_format($turnaround, 1, '.', ''), 'hours', $turnaround === null ? 'neutral' : ($turnaround <= $threshold ? 'good' : ($turnaround <= $threshold * 2 ? 'warn' : 'bad')), 'average, sample logged to decision'),
            $this->kpi('rejection', 'Rejection rate', $rejection, '%', $rejection === null ? 'neutral' : ((float) $rejection <= $rejectionLimit ? 'good' : ((float) $rejection <= $rejectionLimit * 2 ? 'warn' : 'bad')), "{$rejected} of {$decided->count()} decisions"),
            $this->kpi('slow', 'Waiting over '.$threshold.' h', (string) $slow, null, $slow === 0 ? 'good' : ($slow <= 2 ? 'warn' : 'bad'), 'samples pending now'),
        ], [$turnaround === null ? null : max(0, min(100, 100 - ($turnaround - $threshold) * (50 / max(1, $threshold)))), $rejection === null ? null : max(0, 100 - (float) $rejection * 2)]);
    }

    private function manufacturing(?Facility $facility, CarbonImmutable $from, CarbonImmutable $asOf): array
    {
        $completed = $this->completedOrders($facility, $from);
        $yields = $completed->filter(fn (ManufacturingOrder $o) => $o->yield_percentage !== null);
        $yield = $yields->isEmpty() ? null : (string) BigDecimal::of((string) $yields->avg(fn (ManufacturingOrder $o) => (float) $o->yield_percentage))->toScale(1, RoundingMode::HalfUp);
        $floor = (float) config('erp.exceptions.yield_floor_percent', 95);
        $belowFloor = $yields->filter(fn (ManufacturingOrder $o) => (float) $o->yield_percentage < $floor)->count();
        $cycle = $this->averageHours($completed->map(fn (ManufacturingOrder $o) => [$o->started_at, $o->completed_at]));
        $runningLimit = (int) config('erp.exceptions.batch_running_hours', 48);
        $delayed = ManufacturingOrder::query()
            ->where('status', ManufacturingOrderStatus::InProgress->value)
            ->where('started_at', '<', $asOf->subHours($runningLimit))
            ->when($facility, fn ($q) => $q->where('facility_id', $facility->id))
            ->count();

        return $this->department('manufacturing', 'Manufacturing', [
            $this->kpi('batches', 'Batches completed', (string) $completed->count(), null, 'neutral', 'in the period'),
            $this->kpi('yield', 'Yield', $yield, '%', $yield === null ? 'neutral' : ((float) $yield >= $floor ? 'good' : ((float) $yield >= $floor - 5 ? 'warn' : 'bad')), "average across batches; {$belowFloor} below the {$floor}% floor"),
            $this->kpi('cycle', 'Start to finish', $cycle === null ? null : number_format($cycle, 1, '.', ''), 'hours', 'neutral', 'average batch time'),
            $this->kpi('delayed', 'Running over '.$runningLimit.' h', (string) $delayed, null, $delayed === 0 ? 'good' : ($delayed <= 1 ? 'warn' : 'bad'), 'batches in progress now'),
        ], [$yield === null ? null : min(100, (float) $yield)]);
    }

    private function packaging(?Facility $facility, CarbonImmutable $from, CarbonImmutable $asOf): array
    {
        $completed = $this->completedOrders($facility, $from);
        $ids = $completed->pluck('id')->all();

        $consumed = $ids === [] ? BigDecimal::zero() : BigDecimal::of((string) (ManufacturingOrderLine::query()
            ->whereIn('manufacturing_order_id', $ids)
            ->where('store_kind', StoreKind::Packaging->value)
            ->sum('consumed_quantity') ?: '0'));

        $wasted = $ids === [] ? BigDecimal::zero() : BigDecimal::of((string) (ManufacturingOrderAdjustment::query()
            ->whereIn('manufacturing_order_id', $ids)
            ->where('kind', ManufacturingOrderAdjustment::WASTAGE)
            ->whereHas('item', fn ($q) => $q->where('type', ItemType::PackagingMaterial->value))
            ->sum('quantity') ?: '0'));

        $rejection = $consumed->isPositive() ? (string) $wasted->multipliedBy(100)->dividedBy($consumed, 1, RoundingMode::HalfUp) : null;
        $limit = (float) config('erp.exceptions.wastage_percent', 3);
        $units = $completed->sum(fn (ManufacturingOrder $o) => (int) ($o->output_units ?? 0));

        return $this->department('packaging', 'Packaging', [
            $this->kpi('batches', 'Batches packed', (string) $completed->count(), null, 'neutral', 'completed in the period'),
            $this->kpi('units', 'Units packed', (string) $units, null, 'neutral', 'finished units posted'),
            $this->kpi('rejection', 'Packaging rejection', $rejection, '%', $rejection === null ? 'neutral' : ((float) $rejection <= $limit ? 'good' : ((float) $rejection <= $limit * 2 ? 'warn' : 'bad')), 'packaging written off as wastage against packaging used'),
        ], [$rejection === null ? null : max(0, 100 - (float) $rejection * 10)]);
    }

    private function dispatch(?Facility $facility, CarbonImmutable $from, CarbonImmutable $asOf): array
    {
        // Client jobs due in the period, or delivered in it ahead of their
        // date: on time when completed by the delivery date, in full when
        // the units (or quantity) posted reach what was ordered.
        $due = ManufacturingOrder::query()
            ->whereNotNull('client_id')
            ->whereNotNull('required_delivery_at')
            ->where(fn ($q) => $q
                ->whereBetween('required_delivery_at', [$from->toDateString(), $asOf->toDateString()])
                ->orWhere(fn ($c) => $c->whereNotNull('completed_at')->where('completed_at', '>=', $from)))
            ->whereNotIn('status', [ManufacturingOrderStatus::Cancelled->value])
            ->when($facility, fn ($q) => $q->where('facility_id', $facility->id))
            ->get(['id', 'number', 'status', 'required_delivery_at', 'completed_at', 'planned_units', 'output_units', 'planned_quantity', 'output_quantity']);

        $onTime = $due->filter(fn (ManufacturingOrder $o) => $o->completed_at !== null && $o->completed_at->lte(CarbonImmutable::parse($o->required_delivery_at)->endOfDay()));
        $inFull = $due->filter(fn (ManufacturingOrder $o) => $o->status === ManufacturingOrderStatus::Completed && $this->inFull($o));
        $otif = $due->filter(fn (ManufacturingOrder $o) => $onTime->contains('id', $o->id) && $inFull->contains('id', $o->id));
        $late = $due->filter(fn (ManufacturingOrder $o) => $o->completed_at === null && CarbonImmutable::parse($o->required_delivery_at)->endOfDay()->lt($asOf))->count();

        $otifPercent = $this->percent($otif->count(), $due->count());

        return $this->department('dispatch', 'Dispatch (client jobs)', [
            $this->kpi('otif', 'On time, in full', $otifPercent, '%', $this->tone($otifPercent, 95, 80), "{$otif->count()} of {$due->count()} client jobs due"),
            $this->kpi('on_time', 'On time', $this->percent($onTime->count(), $due->count()), '%', $this->tone($this->percent($onTime->count(), $due->count()), 95, 80), 'completed by the delivery date'),
            $this->kpi('in_full', 'In full', $this->percent($inFull->count(), $due->count()), '%', $this->tone($this->percent($inFull->count(), $due->count()), 95, 80), 'units posted reached the order'),
            $this->kpi('late_open', 'Past due, not complete', (string) $late, null, $late === 0 ? 'good' : ($late <= 1 ? 'warn' : 'bad'), 'client jobs overdue now'),
        ], [$otifPercent]);
    }

    // -- Process performance --------------------------------------------------

    /**
     * Each step of the factory's flow: how many went through it, how long
     * it took on average and at the longest, how many are waiting at it
     * now and for how long the oldest has waited. The process is measured,
     * not the person.
     *
     * @return list<array<string, mixed>>
     */
    public function processes(?Facility $facility, CarbonImmutable $from, CarbonImmutable $asOf): array
    {
        $byFacility = fn ($q, string $column = 'facility_id') => $facility ? $q->where($column, $facility->id) : $q;
        $viaWarehouse = fn ($q, string $relation = 'warehouse') => $facility ? $q->whereHas($relation, fn ($w) => $w->where('facility_id', $facility->id)) : $q;

        $steps = [];

        $steps[] = $this->step('plan_check', 'Planning', 'Plan raised → stores checked',
            $byFacility(ProductionPlan::query()->whereNotNull('checked_at')->where('checked_at', '>=', $from))->get(['created_at', 'checked_at'])->map(fn ($p) => [$p->created_at, $p->checked_at]),
            $byFacility(ProductionPlan::query()->where('status', ProductionPlanStatus::Draft->value))->get(['created_at'])->map(fn ($p) => $p->created_at),
            $asOf);

        $steps[] = $this->step('material_request', 'Purchase', 'Material request → fully received',
            MaterialRequest::query()->whereNotNull('fulfilled_at')->where('fulfilled_at', '>=', $from)->when($facility, fn ($q) => $q->whereHas('plan', fn ($p) => $p->where('facility_id', $facility->id)))->get(['requested_at', 'fulfilled_at'])->map(fn ($r) => [$r->requested_at, $r->fulfilled_at]),
            MaterialRequest::query()->whereIn('status', [MaterialRequestStatus::Open->value, MaterialRequestStatus::PartiallyReceived->value])->when($facility, fn ($q) => $q->whereHas('plan', fn ($p) => $p->where('facility_id', $facility->id)))->get(['requested_at'])->map(fn ($r) => $r->requested_at),
            $asOf);

        $steps[] = $this->step('receipt', 'Stores', 'Delivery opened → booked in',
            $viaWarehouse(GoodsReceipt::query()->where('status', GoodsReceiptStatus::Received->value)->where('posted_at', '>=', $from))->get(['created_at', 'posted_at'])->map(fn ($r) => [$r->created_at, $r->posted_at]),
            $viaWarehouse(GoodsReceipt::query()->where('status', GoodsReceiptStatus::Draft->value))->get(['created_at'])->map(fn ($r) => $r->created_at),
            $asOf);

        $steps[] = $this->step('qc', 'Quality control', 'Sample logged → decision',
            $viaWarehouse(QcInspection::query()->whereNotNull('decided_at')->where('decided_at', '>=', $from), 'destinationWarehouse')->get(['created_at', 'decided_at'])->map(fn ($i) => [$i->created_at, $i->decided_at]),
            $viaWarehouse(QcInspection::query()->where('status', LotQcStatus::Pending->value), 'destinationWarehouse')->get(['created_at'])->map(fn ($i) => $i->created_at),
            $asOf);

        $steps[] = $this->step('release', 'Manufacturing', 'Order approved → batch started',
            $byFacility(ManufacturingOrder::query()->whereNotNull('approved_at')->whereNotNull('started_at')->where('started_at', '>=', $from))->get(['approved_at', 'started_at'])->map(fn ($o) => [$o->approved_at, $o->started_at]),
            $byFacility(ManufacturingOrder::query()->where('status', ManufacturingOrderStatus::Approved->value)->whereNotNull('approved_at'))->get(['approved_at'])->map(fn ($o) => $o->approved_at),
            $asOf);

        $steps[] = $this->step('batch', 'Manufacturing', 'Batch started → completed',
            $byFacility(ManufacturingOrder::query()->where('status', ManufacturingOrderStatus::Completed->value)->whereNotNull('started_at')->where('completed_at', '>=', $from))->get(['started_at', 'completed_at'])->map(fn ($o) => [$o->started_at, $o->completed_at]),
            $byFacility(ManufacturingOrder::query()->where('status', ManufacturingOrderStatus::InProgress->value)->whereNotNull('started_at'))->get(['started_at'])->map(fn ($o) => $o->started_at),
            $asOf);

        $steps[] = $this->step('transfer', 'Stores', 'Transfer dispatched → received',
            StockTransfer::query()->whereNotNull('dispatched_at')->whereNotNull('received_at')->where('received_at', '>=', $from)->when($facility, fn ($q) => $q->where('destination_facility_id', $facility->id))->get(['dispatched_at', 'received_at'])->map(fn ($t) => [$t->dispatched_at, $t->received_at]),
            StockTransfer::query()->whereIn('status', [StockTransferStatus::Dispatched->value, StockTransferStatus::InTransit->value])->whereNotNull('dispatched_at')->when($facility, fn ($q) => $q->where('destination_facility_id', $facility->id))->get(['dispatched_at'])->map(fn ($t) => $t->dispatched_at),
            $asOf);

        $steps[] = $this->step('count', 'Stores', 'Count started → approved',
            $viaWarehouse(StockCount::query()->where('status', StockCountStatus::Approved->value)->where('approved_at', '>=', $from))->get(['started_at', 'approved_at'])->map(fn ($c) => [$c->started_at, $c->approved_at]),
            $viaWarehouse(StockCount::query()->whereIn('status', [StockCountStatus::Counting->value, StockCountStatus::Submitted->value]))->get(['started_at'])->map(fn ($c) => $c->started_at),
            $asOf);

        return $steps;
    }

    // -- Helpers ---------------------------------------------------------------

    private function completedOrders(?Facility $facility, CarbonImmutable $from): Collection
    {
        return ManufacturingOrder::query()
            ->where('status', ManufacturingOrderStatus::Completed->value)
            ->where('completed_at', '>=', $from)
            ->when($facility, fn ($q) => $q->where('facility_id', $facility->id))
            ->get(['id', 'number', 'started_at', 'completed_at', 'yield_percentage', 'planned_units', 'output_units', 'planned_quantity', 'output_quantity']);
    }

    private function inFull(ManufacturingOrder $order): bool
    {
        if ($order->planned_units !== null && (int) $order->planned_units > 0) {
            return (int) ($order->output_units ?? 0) >= (int) $order->planned_units;
        }

        return BigDecimal::of($order->output_quantity ?? '0')->isGreaterThanOrEqualTo(BigDecimal::of($order->planned_quantity ?? '0'));
    }

    /**
     * @param  Collection<int, array{0: CarbonInterface|null, 1: CarbonInterface|null}>  $pairs
     */
    private function averageHours(Collection $pairs): ?float
    {
        $hours = $pairs
            ->filter(fn (array $p) => $p[0] !== null && $p[1] !== null)
            ->map(fn (array $p) => max(0, CarbonImmutable::instance($p[0])->diffInMinutes(CarbonImmutable::instance($p[1]), true) / 60));

        return $hours->isEmpty() ? null : round((float) $hours->avg(), 2);
    }

    /**
     * @param  Collection<int, array{0: CarbonInterface|null, 1: CarbonInterface|null}>  $done
     * @param  Collection<int, CarbonInterface|null>  $open
     */
    private function step(string $key, string $department, string $label, Collection $done, Collection $open, CarbonImmutable $asOf): array
    {
        $durations = $done
            ->filter(fn (array $p) => $p[0] !== null && $p[1] !== null)
            ->map(fn (array $p) => max(0, CarbonImmutable::instance($p[0])->diffInMinutes(CarbonImmutable::instance($p[1]), true) / 60));
        $waits = $open->filter()->map(fn ($at) => CarbonImmutable::instance($at)->diffInMinutes($asOf, true) / 60);

        return [
            'key' => $key,
            'department' => $department,
            'label' => $label,
            'volume' => $durations->count(),
            'average_hours' => $durations->isEmpty() ? null : round((float) $durations->avg(), 1),
            'longest_hours' => $durations->isEmpty() ? null : round((float) $durations->max(), 1),
            'open' => $waits->count(),
            'oldest_open_hours' => $waits->isEmpty() ? null : round((float) $waits->max(), 1),
        ];
    }

    private function percent(int $part, int $whole): ?string
    {
        return $whole === 0 ? null : (string) BigDecimal::of($part)->multipliedBy(100)->dividedBy($whole, 1, RoundingMode::HalfUp);
    }

    private function tone(?string $percent, float $good, float $warn): string
    {
        if ($percent === null) {
            return 'neutral';
        }

        return (float) $percent >= $good ? 'good' : ((float) $percent >= $warn ? 'warn' : 'bad');
    }

    private function kpi(string $key, string $label, ?string $value, ?string $unit, string $tone, string $hint): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'unit' => $unit, 'tone' => $tone, 'hint' => $hint];
    }

    /**
     * @param  list<array<string, mixed>>  $kpis
     * @param  list<float|string|null>  $scoreParts  percentages, 0–100, that make the department score
     */
    private function department(string $key, string $name, array $kpis, array $scoreParts): array
    {
        $parts = array_values(array_filter(array_map(fn ($p) => $p === null ? null : (float) $p, $scoreParts), fn ($p) => $p !== null));
        $score = $parts === [] ? null : (int) round(array_sum($parts) / count($parts));

        return [
            'key' => $key,
            'name' => $name,
            'score' => $score,
            'tone' => $score === null ? 'neutral' : ($score >= 90 ? 'good' : ($score >= 70 ? 'warn' : 'bad')),
            'kpis' => $kpis,
        ];
    }
}
