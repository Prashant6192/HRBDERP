<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Services;

use App\Domain\Intelligence\DTOs\FactoryException;
use App\Domain\Intelligence\DTOs\ItemOutlook;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Manufacturing\Enums\ManufacturingOrderStatus;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Marketplace\Enums\ShipmentStatus;
use App\Domain\Marketplace\Enums\StockState;
use App\Domain\Marketplace\Models\LabelBatch;
use App\Domain\Marketplace\Models\Shipment;
use App\Domain\Marketplace\Support\Cutoff;
use App\Domain\Planning\Enums\MaterialRequestStatus;
use App\Domain\Planning\Enums\ProductionPlanStatus;
use App\Domain\Planning\Models\MaterialRequest;
use App\Domain\Planning\Models\ProductionPlan;
use App\Domain\Procurement\Enums\GoodsReceiptStatus;
use App\Domain\Quality\Models\QcInspection;
use App\Domain\Warehousing\Models\Facility;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Exception-based management.
 *
 * Every rule here reads the same tables the screens do and reports only
 * what crosses a threshold from config/erp.php. Nothing is stored: an
 * exception exists for as long as the condition does, and its key stays
 * the same throughout, which is what lets the escalation engine act once
 * per level rather than once per hour.
 */
class ExceptionService
{
    public function __construct(private readonly StockOutlookService $outlook) {}

    /**
     * Every current exception, most severe and longest-standing first.
     *
     * @return Collection<int, FactoryException>
     */
    public function detect(?Facility $facility = null, ?CarbonImmutable $asOf = null): Collection
    {
        $asOf ??= CarbonImmutable::now();
        $rank = [FactoryException::HIGH => 0, FactoryException::MEDIUM => 1, FactoryException::LOW => 2];

        return collect()
            ->merge($this->slowQc($facility, $asOf))
            ->merge($this->delayedBatches($facility, $asOf))
            ->merge($this->overduePlans($facility, $asOf))
            ->merge($this->unfilledRequests($facility, $asOf))
            ->merge($this->stockoutImminent($facility, $asOf))
            ->merge($this->lateTransfers($facility, $asOf))
            ->merge($this->discrepancies($facility, $asOf))
            ->merge($this->priceIncreases($asOf))
            ->merge($this->highRejection($asOf))
            ->merge($this->belowTarget($facility, $asOf))
            ->merge($this->materialVariance($facility, $asOf))
            ->merge($this->abnormalWastage($facility, $asOf))
            ->merge($this->parcelsNotPacked($facility, $asOf))
            ->merge($this->parcelsBlocked($facility))
            ->sortBy([
                fn (FactoryException $a, FactoryException $b) => $rank[$a->severity] <=> $rank[$b->severity],
                fn (FactoryException $a, FactoryException $b) => $a->since->getTimestamp() <=> $b->since->getTimestamp(),
            ])
            ->values();
    }

    /**
     * @return Collection<int, FactoryException>
     */
    private function slowQc(?Facility $facility, CarbonImmutable $asOf): Collection
    {
        $hours = (int) config('erp.exceptions.qc_pending_hours', 6);
        $storeIds = $facility?->stores()->pluck('id')->all();

        return QcInspection::query()
            ->where('status', LotQcStatus::Pending->value)
            ->where('created_at', '<=', $asOf->subHours($hours))
            ->when($storeIds !== null, fn ($q) => $q->whereIn('destination_warehouse_id', $storeIds))
            ->with(['item:id,name', 'lot:id,batch_number'])
            ->orderBy('created_at')
            ->get()
            ->map(function (QcInspection $i) use ($asOf, $hours): FactoryException {
                $age = round($i->created_at->diffInHours($asOf, true));

                return new FactoryException(
                    rule: 'slow_qc',
                    subject: $i->number,
                    severity: $age >= $hours * 2 ? FactoryException::HIGH : FactoryException::MEDIUM,
                    title: "{$i->number} waiting for QC for {$age} hours",
                    detail: ($i->item?->name ?? 'Batch').' '.($i->lot?->batch_number ?? '').' has been in quarantine since '.$i->created_at->format('j M H:i').'.',
                    href: route('qc.show', $i),
                    since: CarbonImmutable::instance($i->created_at),
                    metrics: ['hours' => $age, 'threshold_hours' => $hours],
                );
            });
    }

    /**
     * @return Collection<int, FactoryException>
     */
    private function delayedBatches(?Facility $facility, CarbonImmutable $asOf): Collection
    {
        $hours = (int) config('erp.exceptions.batch_running_hours', 48);

        return ManufacturingOrder::query()
            ->where('status', ManufacturingOrderStatus::InProgress->value)
            ->whereNotNull('started_at')
            ->where('started_at', '<=', $asOf->subHours($hours))
            ->when($facility, fn ($q) => $q->where('facility_id', $facility->id))
            ->with('product:id,name')
            ->orderBy('started_at')
            ->get()
            ->map(function (ManufacturingOrder $o) use ($asOf, $hours): FactoryException {
                $age = round($o->started_at->diffInHours($asOf, true));

                return new FactoryException(
                    rule: 'delayed_batch',
                    subject: $o->number,
                    severity: $age >= $hours * 2 ? FactoryException::HIGH : FactoryException::MEDIUM,
                    title: "{$o->number} has been running for {$age} hours",
                    detail: ($o->product?->name ?? 'The batch').' started on '.$o->started_at->format('j M H:i').' and is not complete; batches usually finish within '.$hours.' hours.',
                    href: route('manufacturing.show', $o),
                    since: CarbonImmutable::instance($o->started_at)->addHours($hours),
                    metrics: ['hours' => $age, 'threshold_hours' => $hours],
                    facilityId: $o->facility_id,
                );
            });
    }

    /**
     * @return Collection<int, FactoryException>
     */
    private function overduePlans(?Facility $facility, CarbonImmutable $asOf): Collection
    {
        return ProductionPlan::query()
            ->whereIn('status', [ProductionPlanStatus::Checked->value, ProductionPlanStatus::Requested->value])
            ->whereNotNull('planned_start_date')
            ->where('planned_start_date', '<', $asOf->toDateString())
            ->when($facility, fn ($q) => $q->where('facility_id', $facility->id))
            ->with('product:id,name')
            ->orderBy('planned_start_date')
            ->get()
            ->map(function (ProductionPlan $p) use ($asOf): FactoryException {
                $start = CarbonImmutable::parse($p->planned_start_date);
                $days = (int) $start->diffInDays($asOf, true);
                $short = $p->hasShortage();

                return new FactoryException(
                    rule: 'overdue_plan',
                    subject: $p->number,
                    severity: $days >= 3 ? FactoryException::HIGH : FactoryException::MEDIUM,
                    title: "{$p->number} was due to start {$days} day".($days === 1 ? '' : 's').' ago',
                    detail: ($p->product?->name ?? 'The plan').' was planned for '.$start->format('j M').($short ? ' and still has materials short.' : ' and has not become a batch.'),
                    href: route('plans.show', $p),
                    since: $start,
                    metrics: ['days' => $days, 'short' => $short],
                    facilityId: $p->facility_id,
                );
            });
    }

    /**
     * @return Collection<int, FactoryException>
     */
    private function unfilledRequests(?Facility $facility, CarbonImmutable $asOf): Collection
    {
        $hours = (int) config('erp.exceptions.pmr_unfilled_hours', 24);
        $storeIds = $facility?->stores()->pluck('id')->all();

        return MaterialRequest::query()
            ->whereIn('status', [MaterialRequestStatus::Open->value, MaterialRequestStatus::PartiallyReceived->value])
            ->when($storeIds !== null, fn ($q) => $q->whereIn('warehouse_id', $storeIds))
            ->with(['lines' => fn ($q) => $q->whereColumn('received_quantity', '<', 'quantity_to_order'), 'lines.item:id,name'])
            ->get()
            ->map(function (MaterialRequest $r) use ($asOf, $hours): ?FactoryException {
                $since = $r->needed_by !== null
                    ? CarbonImmutable::parse($r->needed_by)->endOfDay()
                    : CarbonImmutable::instance($r->requested_at)->addHours($hours);

                if ($since->greaterThan($asOf) || $r->lines->isEmpty()) {
                    return null;
                }

                $age = round($since->diffInHours($asOf, true));
                $items = $r->lines->map(fn ($l) => $l->item?->name)->filter()->take(3)->implode(', ');

                return new FactoryException(
                    rule: 'pmr_overdue',
                    subject: $r->number,
                    severity: $age >= 72 ? FactoryException::HIGH : FactoryException::MEDIUM,
                    title: "{$r->number} unfilled ".($r->needed_by ? 'past its need-by date' : "for {$age} hours"),
                    detail: 'Still short: '.$items.($r->lines->count() > 3 ? ' and more' : '').'.',
                    href: route('material-requests.show', $r),
                    since: $since,
                    metrics: ['hours' => $age, 'lines_short' => $r->lines->count()],
                );
            })
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, FactoryException>
     */
    private function stockoutImminent(?Facility $facility, CarbonImmutable $asOf): Collection
    {
        return $this->outlook->reorderAdvice($facility, asOf: $asOf)
            ->filter(fn (ItemOutlook $o) => $o->status === ItemOutlook::ORDER_TODAY)
            ->map(function (ItemOutlook $o) use ($asOf): FactoryException {
                $deadline = collect([$o->neededBy, $o->runsOutAt])->filter()->min();
                $tomorrow = $deadline instanceof CarbonImmutable && $deadline->lessThanOrEqualTo($asOf->addDay()->endOfDay());
                $unit = $o->unit;

                return new FactoryException(
                    rule: 'stockout_imminent',
                    subject: $o->code,
                    severity: $tomorrow ? FactoryException::HIGH : FactoryException::MEDIUM,
                    title: "{$o->name} will run short".($deadline instanceof CarbonImmutable ? ' by '.$deadline->format('j M') : ''),
                    detail: rtrim(rtrim((string) $o->usable, '0'), '.')." {$unit} usable against ".rtrim(rtrim((string) $o->upcomingRequirement, '0'), '.')." {$unit} needed; ".rtrim(rtrim((string) $o->recommendedQuantity, '0'), '.')." {$unit} must be ordered today with a {$o->leadTimeDays}-day lead time.",
                    href: route('purchase.reorder-advice'),
                    since: $o->orderBy ?? $asOf->startOfDay(),
                    metrics: ['usable' => (string) $o->usable, 'needed' => (string) $o->upcomingRequirement, 'recommended' => (string) $o->recommendedQuantity, 'deadline' => $deadline instanceof CarbonImmutable ? $deadline->toDateString() : null],
                );
            })
            ->values();
    }

    /**
     * @return Collection<int, FactoryException>
     */
    private function lateTransfers(?Facility $facility, CarbonImmutable $asOf): Collection
    {
        $days = (int) config('erp.exceptions.transit_days', 3);

        return StockTransfer::query()
            ->whereIn('status', [StockTransferStatus::Dispatched->value, StockTransferStatus::InTransit->value])
            ->when($facility, fn ($q) => $q->where(fn ($q) => $q->where('source_facility_id', $facility->id)->orWhere('destination_facility_id', $facility->id)))
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereNotNull('expected_at')->where('expected_at', '<', $asOf->toDateString()))
                ->orWhere(fn ($q) => $q->whereNull('expected_at')->whereNotNull('dispatched_at')->where('dispatched_at', '<=', $asOf->subDays($days))))
            ->with(['destinationFacility:id,name', 'sourceFacility:id,name'])
            ->get()
            ->map(function (StockTransfer $t) use ($asOf, $days): FactoryException {
                $since = $t->expected_at ? CarbonImmutable::parse($t->expected_at)->endOfDay() : CarbonImmutable::instance($t->dispatched_at)->addDays($days);

                return new FactoryException(
                    rule: 'late_transfer',
                    subject: $t->number,
                    severity: FactoryException::MEDIUM,
                    title: "{$t->number} has not arrived",
                    detail: 'From '.($t->sourceFacility?->name ?? '—').' to '.($t->destinationFacility?->name ?? '—').', dispatched '.($t->dispatched_at?->format('j M') ?? '—').($t->expected_at ? ', expected '.CarbonImmutable::parse($t->expected_at)->format('j M') : '').'.',
                    href: route('transfers.show', $t),
                    since: $since,
                    metrics: ['days_late' => (int) $since->diffInDays($asOf, true)],
                );
            });
    }

    /**
     * @return Collection<int, FactoryException>
     */
    private function discrepancies(?Facility $facility, CarbonImmutable $asOf): Collection
    {
        return StockTransfer::query()
            ->where('status', StockTransferStatus::Discrepancy->value)
            ->when($facility, fn ($q) => $q->where(fn ($q) => $q->where('source_facility_id', $facility->id)->orWhere('destination_facility_id', $facility->id)))
            ->with(['lines' => fn ($q) => $q->whereColumn('quantity_received', '<>', 'quantity_dispatched'), 'lines.item:id,name'])
            ->get()
            ->map(function (StockTransfer $t) use ($asOf): FactoryException {
                $items = $t->lines->map(fn ($l) => $l->item?->name)->filter()->take(3)->implode(', ');

                return new FactoryException(
                    rule: 'stock_discrepancy',
                    subject: $t->number,
                    severity: FactoryException::HIGH,
                    title: "{$t->number} received with a discrepancy",
                    detail: 'What arrived differs from what was dispatched'.($items !== '' ? ": {$items}." : '.'),
                    href: route('transfers.show', $t),
                    since: CarbonImmutable::instance($t->received_at ?? $t->updated_at ?? $asOf),
                    metrics: ['lines' => $t->lines->count()],
                );
            });
    }

    /**
     * The latest priced receipt of a material against the average of the
     * ones before it in the last year.
     *
     * @return Collection<int, FactoryException>
     */
    private function priceIncreases(CarbonImmutable $asOf): Collection
    {
        $threshold = (float) config('erp.exceptions.price_increase_percent', 10);
        $lookback = (int) config('erp.exceptions.lookback_days', 30);
        $since = $asOf->subDays((int) config('erp.intelligence.price_history_days', 365));

        $lines = DB::table('goods_receipt_lines as l')
            ->join('goods_receipts as g', 'g.id', '=', 'l.goods_receipt_id')
            ->join('items as i', 'i.id', '=', 'l.item_id')
            ->leftJoin('vendors as v', 'v.id', '=', 'g.vendor_id')
            ->where('g.status', GoodsReceiptStatus::Received->value)
            ->whereNotNull('g.posted_at')
            ->where('g.posted_at', '>=', $since)
            ->whereNotNull('l.unit_price')
            ->where('l.stock_quantity', '>', 0)
            ->selectRaw('l.item_id, i.code, i.name, g.id AS receipt_id, g.number AS receipt_number, g.posted_at, v.name AS vendor, (l.unit_price * l.quantity / l.stock_quantity) AS price')
            ->orderBy('g.posted_at')
            ->get();

        return $lines->groupBy('item_id')->map(function (Collection $rows) use ($asOf, $threshold, $lookback): ?FactoryException {
            if ($rows->count() < 2) {
                return null;
            }

            $latest = $rows->last();
            $postedAt = CarbonImmutable::parse($latest->posted_at);

            if ($postedAt->lessThan($asOf->subDays($lookback))) {
                return null;
            }

            $previous = $rows->slice(0, -1)->map(fn ($r) => BigDecimal::of($r->price));
            $average = $previous->reduce(fn (BigDecimal $c, BigDecimal $p) => $c->plus($p), BigDecimal::zero())->dividedBy($previous->count(), 4, RoundingMode::HalfUp);

            if (! $average->isPositive()) {
                return null;
            }

            $price = BigDecimal::of($latest->price);
            $increase = $price->minus($average)->multipliedBy(100)->dividedBy($average, 1, RoundingMode::HalfUp);

            if ($increase->toFloat() < $threshold) {
                return null;
            }

            return new FactoryException(
                rule: 'price_increase',
                subject: "{$latest->code}:{$latest->receipt_id}",
                severity: $increase->toFloat() >= $threshold * 2 ? FactoryException::HIGH : FactoryException::MEDIUM,
                title: "{$latest->name} came in {$increase}% dearer",
                detail: '₹'.number_format($price->toFloat(), 2).' per unit on '.$latest->receipt_number.($latest->vendor ? " from {$latest->vendor}" : '').' against an average of ₹'.number_format($average->toFloat(), 2).' over the previous '.$previous->count().' deliveries.',
                href: route('goods-receipts.show', $latest->receipt_id),
                since: $postedAt,
                metrics: ['price' => (string) $price->toScale(4, RoundingMode::HalfUp), 'average' => (string) $average, 'increase_percent' => (string) $increase, 'vendor' => $latest->vendor],
            );
        })->filter()->values();
    }

    /**
     * @return Collection<int, FactoryException>
     */
    private function highRejection(CarbonImmutable $asOf): Collection
    {
        $threshold = (float) config('erp.exceptions.rejection_percent', 10);
        $lookback = (int) config('erp.exceptions.lookback_days', 30);

        $rows = DB::table('qc_inspections as q')
            ->join('items as i', 'i.id', '=', 'q.item_id')
            ->whereIn('q.status', [LotQcStatus::Approved->value, LotQcStatus::Rejected->value])
            ->where('q.decided_at', '>=', $asOf->subDays($lookback))
            ->groupBy('q.item_id', 'i.code', 'i.name')
            ->selectRaw("q.item_id, i.code, i.name, COUNT(*) AS decisions, SUM(CASE WHEN q.status = 'rejected' THEN 1 ELSE 0 END) AS rejected, MAX(CASE WHEN q.status = 'rejected' THEN q.decided_at END) AS last_rejected_at")
            ->get();

        return $rows->map(function ($r) use ($threshold, $lookback): ?FactoryException {
            if ((int) $r->rejected === 0 || (int) $r->decisions < 2) {
                return null;
            }

            $percent = round((int) $r->rejected / (int) $r->decisions * 100, 1);

            if ($percent < $threshold) {
                return null;
            }

            return new FactoryException(
                rule: 'high_rejection',
                subject: $r->code,
                severity: $percent >= $threshold * 2 ? FactoryException::HIGH : FactoryException::MEDIUM,
                title: "{$r->name}: {$r->rejected} of {$r->decisions} batches rejected",
                detail: "{$percent}% of QC decisions in the last {$lookback} days went against it.",
                href: route('qc.index', ['status' => 'rejected']),
                since: CarbonImmutable::parse($r->last_rejected_at),
                metrics: ['rejected' => (int) $r->rejected, 'decisions' => (int) $r->decisions, 'percent' => $percent],
            );
        })->filter()->values();
    }

    /**
     * @return Collection<int, FactoryException>
     */
    private function belowTarget(?Facility $facility, CarbonImmutable $asOf): Collection
    {
        $floor = (float) config('erp.exceptions.yield_floor_percent', 95);
        $lookback = (int) config('erp.exceptions.lookback_days', 30);

        return ManufacturingOrder::query()
            ->where('status', ManufacturingOrderStatus::Completed->value)
            ->where('completed_at', '>=', $asOf->subDays($lookback))
            ->whereNotNull('yield_percentage')
            ->where('yield_percentage', '<', $floor)
            ->when($facility, fn ($q) => $q->where('facility_id', $facility->id))
            ->with('product:id,name')
            ->orderBy('completed_at')
            ->get()
            ->map(function (ManufacturingOrder $o) use ($floor): FactoryException {
                $yield = rtrim(rtrim((string) $o->yield_percentage, '0'), '.');
                $variance = $o->planned_units !== null && $o->output_units !== null ? $o->planned_units - $o->output_units : null;

                return new FactoryException(
                    rule: 'production_below_target',
                    subject: $o->number,
                    severity: (float) $o->yield_percentage < $floor - 5 ? FactoryException::HIGH : FactoryException::MEDIUM,
                    title: "{$o->number} yielded {$yield}%",
                    detail: ($o->product?->name ?? 'The batch').($variance !== null ? ": expected {$o->planned_units} units, produced {$o->output_units} — {$variance} short." : ' came in under the '.$floor.'% target.'),
                    href: route('manufacturing.show', $o),
                    since: CarbonImmutable::instance($o->completed_at),
                    metrics: ['yield' => (string) $o->yield_percentage, 'planned_units' => $o->planned_units, 'output_units' => $o->output_units, 'floor' => $floor],
                    facilityId: $o->facility_id,
                );
            });
    }

    /**
     * Consumed more than the recipe said, per material, on recent batches.
     *
     * @return Collection<int, FactoryException>
     */
    private function materialVariance(?Facility $facility, CarbonImmutable $asOf): Collection
    {
        $threshold = (float) config('erp.exceptions.material_variance_percent', 5);
        $lookback = (int) config('erp.exceptions.lookback_days', 30);

        $rows = DB::table('manufacturing_order_lines as l')
            ->join('manufacturing_orders as o', 'o.id', '=', 'l.manufacturing_order_id')
            ->join('items as i', 'i.id', '=', 'l.item_id')
            ->leftJoin('uoms as u', 'u.id', '=', 'l.uom_id')
            ->whereNull('o.deleted_at')
            ->where('o.status', ManufacturingOrderStatus::Completed->value)
            ->where('o.completed_at', '>=', $asOf->subDays($lookback))
            ->when($facility, fn ($q) => $q->where('o.facility_id', $facility->id))
            ->where('l.planned_quantity', '>', 0)
            ->where('l.consumed_quantity', '>', 0)
            ->selectRaw('l.item_id, i.code, i.name, u.code AS unit, COUNT(*) AS batches, SUM(l.planned_quantity) AS planned, SUM(l.consumed_quantity) AS consumed, MAX(o.completed_at) AS last_completed_at')
            ->groupBy('l.item_id', 'i.code', 'i.name', 'u.code')
            ->get();

        return $rows->map(function ($r) use ($threshold): ?FactoryException {
            $planned = BigDecimal::of($r->planned);
            $consumed = BigDecimal::of($r->consumed);
            $variance = $consumed->minus($planned)->multipliedBy(100)->dividedBy($planned, 1, RoundingMode::HalfUp);

            if ($variance->toFloat() < $threshold) {
                return null;
            }

            return new FactoryException(
                rule: 'material_variance',
                subject: $r->code,
                severity: $variance->toFloat() >= $threshold * 2 ? FactoryException::HIGH : FactoryException::MEDIUM,
                title: "{$r->name} consumption {$variance}% above standard",
                detail: "Over the last {$r->batches} batch".((int) $r->batches === 1 ? '' : 'es').': '.rtrim(rtrim((string) $consumed, '0'), '.')." {$r->unit} used against ".rtrim(rtrim((string) $planned, '0'), '.')." {$r->unit} in the recipes.",
                href: route('manufacturing.index', ['status' => 'completed']),
                since: CarbonImmutable::parse($r->last_completed_at),
                metrics: ['batches' => (int) $r->batches, 'planned' => (string) $planned, 'consumed' => (string) $consumed, 'variance_percent' => (string) $variance],
            );
        })->filter()->values();
    }

    /**
     * Damage, expiry, samples and write-offs against what production used.
     *
     * @return Collection<int, FactoryException>
     */
    private function abnormalWastage(?Facility $facility, CarbonImmutable $asOf): Collection
    {
        $threshold = (float) config('erp.exceptions.wastage_percent', 3);
        $lookback = (int) config('erp.exceptions.lookback_days', 30);
        $storeIds = $facility?->stores()->pluck('id')->all();

        $wasteTypes = [
            InventoryTransactionType::Damage->value,
            InventoryTransactionType::Expiry->value,
            InventoryTransactionType::Sample->value,
            InventoryTransactionType::StockAdjustmentOut->value,
            InventoryTransactionType::QcRejection->value,
        ];

        $rows = DB::table('inventory_transaction_lines as l')
            ->join('inventory_transactions as t', 't.id', '=', 'l.inventory_transaction_id')
            ->join('items as i', 'i.id', '=', 'l.item_id')
            ->leftJoin('uoms as u', 'u.id', '=', 'i.stock_uom_id')
            ->where('l.quantity', '<', 0)
            ->where('t.transacted_at', '>=', $asOf->subDays($lookback))
            ->whereIn('t.type', [...$wasteTypes, InventoryTransactionType::ProductionConsumption->value])
            ->when($storeIds !== null, fn ($q) => $q->whereIn('l.warehouse_id', $storeIds))
            ->selectRaw("l.item_id, i.code, i.name, u.code AS unit, SUM(CASE WHEN t.type = 'PRODUCTION_CONSUMPTION' THEN ABS(l.quantity) ELSE 0 END) AS consumed, SUM(CASE WHEN t.type <> 'PRODUCTION_CONSUMPTION' THEN ABS(l.quantity) ELSE 0 END) AS wasted, MAX(CASE WHEN t.type <> 'PRODUCTION_CONSUMPTION' THEN t.transacted_at END) AS last_wasted_at")
            ->groupBy('l.item_id', 'i.code', 'i.name', 'u.code')
            ->get();

        return $rows->map(function ($r) use ($threshold, $lookback): ?FactoryException {
            $wasted = BigDecimal::of($r->wasted);
            $consumed = BigDecimal::of($r->consumed);

            if (! $wasted->isPositive()) {
                return null;
            }

            $base = $consumed->plus($wasted);
            $percent = $wasted->multipliedBy(100)->dividedBy($base, 1, RoundingMode::HalfUp);

            if ($percent->toFloat() < $threshold) {
                return null;
            }

            return new FactoryException(
                rule: 'abnormal_wastage',
                subject: $r->code,
                severity: $percent->toFloat() >= $threshold * 3 ? FactoryException::HIGH : FactoryException::MEDIUM,
                title: "{$r->name}: {$percent}% wastage",
                detail: rtrim(rtrim((string) $wasted, '0'), '.')." {$r->unit} damaged, expired, sampled or written off in the last {$lookback} days against ".rtrim(rtrim((string) $consumed, '0'), '.')." {$r->unit} used in production.",
                href: route('lots.index'),
                since: CarbonImmutable::parse($r->last_wasted_at),
                metrics: ['wasted' => (string) $wasted, 'consumed' => (string) $consumed, 'percent' => (string) $percent],
            );
        })->filter()->values();
    }

    /**
     * Marketplace labels printed (or not even printed) and still not packed
     * after the day's cut-off: one exception per facility per day, standing
     * until every parcel is packed or cancelled with a reason.
     *
     * @return Collection<int, FactoryException>
     */
    private function parcelsNotPacked(?Facility $facility, CarbonImmutable $asOf): Collection
    {
        return Shipment::query()
            ->join('label_batches', 'label_batches.id', '=', 'shipments.label_batch_id')
            ->join('facilities', 'facilities.id', '=', 'label_batches.facility_id')
            ->whereIn('shipments.status', ShipmentStatus::awaitingPacking())
            ->whereDate('label_batches.for_date', '<=', Cutoff::today($asOf)->toDateString())
            ->when($facility, fn ($q) => $q->where('label_batches.facility_id', $facility->id))
            ->selectRaw("label_batches.facility_id, facilities.name AS facility, facilities.code AS facility_code, label_batches.for_date::date AS day, COUNT(*) AS parcels, SUM(CASE WHEN shipments.status = 'printed' THEN 1 ELSE 0 END) AS printed")
            ->groupBy('label_batches.facility_id', 'facilities.name', 'facilities.code', 'label_batches.for_date')
            ->toBase()
            ->get()
            ->filter(fn ($row) => Cutoff::passed((string) $row->day, $asOf))
            ->groupBy(fn ($row) => $row->facility_id.'|'.$row->day)
            ->map(function (Collection $rows) use ($asOf): FactoryException {
                $first = $rows->first();
                $day = (string) $first->day;
                $parcels = (int) $rows->sum('parcels');
                $printed = (int) $rows->sum('printed');
                $since = Cutoff::on($day);

                return new FactoryException(
                    rule: 'parcels_not_packed',
                    subject: "{$first->facility_code}:{$day}",
                    severity: FactoryException::HIGH,
                    title: "{$parcels} online order(s) not packed at {$first->facility}",
                    detail: sprintf(
                        '%s: %d label(s) printed and not packed, %d not even printed. The cut-off was %s.',
                        CarbonImmutable::parse($day)->format('j M'),
                        $printed,
                        $parcels - $printed,
                        $since->format('g:i A'),
                    ),
                    href: route('online-orders.index', ['date' => $day, 'facility' => $first->facility_id]),
                    since: $since,
                    metrics: ['parcels' => $parcels, 'printed' => $printed, 'hours_late' => round($since->diffInMinutes($asOf, true) / 60, 1)],
                    facilityId: (int) $first->facility_id,
                );
            })
            ->values();
    }

    /**
     * Parcels that cannot be packed: the store is short, the label's SKU is
     * not mapped to a product, or its AWB was never read.
     *
     * @return Collection<int, FactoryException>
     */
    private function parcelsBlocked(?Facility $facility): Collection
    {
        return LabelBatch::query()
            ->when($facility, fn ($q) => $q->where('facility_id', $facility->id))
            ->whereHas('shipments', fn ($q) => $this->blocked($q))
            ->withCount([
                'shipments as short' => fn ($q) => $this->blocked($q)->where('stock_state', StockState::Short->value),
                'shipments as unmapped' => fn ($q) => $this->blocked($q)->where('stock_state', StockState::Unmapped->value),
                'shipments as no_awb' => fn ($q) => $this->blocked($q)->whereNull('awb'),
            ])
            ->with(['brand:id,name', 'marketplace:id,name', 'facility:id,name'])
            ->get()
            ->map(function (LabelBatch $b): FactoryException {
                $parts = array_filter([
                    $b->short > 0 ? "{$b->short} short of stock" : null,
                    $b->unmapped > 0 ? "{$b->unmapped} with a product not mapped" : null,
                    $b->no_awb > 0 ? "{$b->no_awb} with no AWB" : null,
                ]);

                return new FactoryException(
                    rule: 'parcels_blocked',
                    subject: $b->number,
                    severity: FactoryException::MEDIUM,
                    title: "{$b->brand?->name} {$b->marketplace?->name} parcels cannot be packed",
                    detail: "{$b->number} at {$b->facility?->name}: ".implode(', ', $parts).'.',
                    href: route('online-orders.show', $b),
                    since: CarbonImmutable::instance($b->created_at),
                    metrics: ['short' => (int) $b->short, 'unmapped' => (int) $b->unmapped, 'no_awb' => (int) $b->no_awb],
                    facilityId: $b->facility_id,
                );
            })
            ->values();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Shipment>|\Illuminate\Database\Eloquent\Relations\Relation<Shipment, *, *>  $query
     */
    private function blocked($query)
    {
        return $query
            ->whereIn('status', ShipmentStatus::awaitingPacking())
            ->where(fn ($q) => $q->whereIn('stock_state', [StockState::Short->value, StockState::Unmapped->value])->orWhereNull('awb'));
    }
}
