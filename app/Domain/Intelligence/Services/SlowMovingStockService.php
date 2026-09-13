<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Services;

use App\Domain\Warehousing\Models\Facility;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stock that has stopped moving, and the money tied up in it.
 *
 * A lot is idle from the day it was last issued — or, if it never was,
 * from the day it arrived. It is bucketed by how long that has been
 * (30, 60, 90, 180 days) and valued at its landed cost, falling back to
 * the item's standard cost, so management sees working capital, not just
 * quantities.
 */
class SlowMovingStockService
{
    /**
     * @return array{buckets: list<array{days: int, label: string, lots: int, value: string}>, total_value: string, rows: list<array<string, mixed>>}
     */
    public function report(?Facility $facility = null, int $minimumIdleDays = 30, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();
        $storeIds = $facility?->stores()->pluck('id')->all();

        $balances = DB::table('stock_balances as b')
            ->join('inventory_lots as l', 'l.id', '=', 'b.lot_id')
            ->join('items as i', 'i.id', '=', 'l.item_id')
            ->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->leftJoin('uoms as u', 'u.id', '=', 'i.stock_uom_id')
            ->where('b.on_hand', '>', 0)
            ->where('w.is_quarantine', false)
            ->when($storeIds !== null, fn ($q) => $q->whereIn('b.warehouse_id', $storeIds))
            ->selectRaw('l.id AS lot_id, l.batch_number, l.received_at, l.created_at AS lot_created_at, l.expiry_at, l.unit_cost, l.qc_status, i.id AS item_id, i.code, i.name, i.type, i.standard_cost, u.code AS unit, w.id AS warehouse_id, w.code AS warehouse_code, w.name AS warehouse_name, SUM(b.on_hand) AS on_hand')
            ->groupBy('l.id', 'l.batch_number', 'l.received_at', 'l.created_at', 'l.expiry_at', 'l.unit_cost', 'l.qc_status', 'i.id', 'i.code', 'i.name', 'i.type', 'i.standard_cost', 'u.code', 'w.id', 'w.code', 'w.name')
            ->get();

        if ($balances->isEmpty()) {
            return ['buckets' => $this->emptyBuckets(), 'total_value' => '0.00', 'rows' => []];
        }

        $lastOut = DB::table('inventory_transaction_lines as tl')
            ->join('inventory_transactions as t', 't.id', '=', 'tl.inventory_transaction_id')
            ->whereIn('tl.lot_id', $balances->pluck('lot_id')->unique()->all())
            ->where('tl.quantity', '<', 0)
            ->groupBy('tl.lot_id')
            ->selectRaw('tl.lot_id, MAX(t.transacted_at) AS last_out_at')
            ->pluck('last_out_at', 'lot_id');

        $rows = $balances->map(function ($row) use ($lastOut, $asOf): array {
            $idleSince = CarbonImmutable::parse($lastOut[$row->lot_id] ?? $row->received_at ?? $row->lot_created_at);
            $idleDays = (int) floor($idleSince->diffInDays($asOf, true));
            $onHand = BigDecimal::of($row->on_hand);
            $unitCost = $row->unit_cost ?? $row->standard_cost;
            $value = $unitCost === null ? null : $onHand->multipliedBy(BigDecimal::of($unitCost))->toScale(2, RoundingMode::HalfUp);

            return [
                'lot_id' => (int) $row->lot_id,
                'batch_number' => $row->batch_number,
                'item_id' => (int) $row->item_id,
                'code' => $row->code,
                'name' => $row->name,
                'type' => $row->type,
                'unit' => $row->unit,
                'warehouse' => ['id' => (int) $row->warehouse_id, 'code' => $row->warehouse_code, 'name' => $row->warehouse_name],
                'on_hand' => (string) $onHand,
                'unit_cost' => $unitCost === null ? null : (string) $unitCost,
                'value' => $value === null ? null : (string) $value,
                'idle_since' => $idleSince->toDateString(),
                'idle_days' => $idleDays,
                'bucket' => $this->bucketFor($idleDays),
                'ever_issued' => isset($lastOut[$row->lot_id]),
                'expiry_at' => $row->expiry_at,
            ];
        })
            ->filter(fn (array $r) => $r['idle_days'] >= $minimumIdleDays)
            ->sortByDesc('idle_days')
            ->values();

        $buckets = collect($this->emptyBuckets())->map(function (array $bucket) use ($rows): array {
            $in = $rows->where('bucket', $bucket['days']);

            return [
                ...$bucket,
                'lots' => $in->count(),
                'value' => (string) $in->reduce(fn (BigDecimal $c, array $r) => $c->plus(BigDecimal::of($r['value'] ?? '0')), BigDecimal::zero())->toScale(2, RoundingMode::HalfUp),
            ];
        })->values()->all();

        $total = $rows->reduce(fn (BigDecimal $c, array $r) => $c->plus(BigDecimal::of($r['value'] ?? '0')), BigDecimal::zero());

        return [
            'buckets' => $buckets,
            'total_value' => (string) $total->toScale(2, RoundingMode::HalfUp),
            'rows' => $rows->all(),
        ];
    }

    /**
     * The bucket an idle age falls in: the largest threshold it exceeds,
     * or 0 when it is under the smallest.
     */
    public function bucketFor(int $idleDays): int
    {
        foreach ($this->thresholds() as $days) {
            if ($idleDays >= $days) {
                return $days;
            }
        }

        return 0;
    }

    /**
     * @return list<int>
     */
    public function thresholds(): array
    {
        $thresholds = array_map('intval', (array) config('erp.intelligence.slow_moving_buckets', [180, 90, 60, 30]));
        rsort($thresholds);

        return array_values(array_filter($thresholds, fn (int $d) => $d > 0));
    }

    /**
     * @return list<array{days: int, label: string, lots: int, value: string}>
     */
    private function emptyBuckets(): array
    {
        $thresholds = $this->thresholds();
        $buckets = [];

        foreach ($thresholds as $i => $days) {
            $next = $thresholds[$i - 1] ?? null;
            $buckets[] = [
                'days' => $days,
                'label' => $next === null ? "Over {$days} days" : "{$days}–".($next - 1).' days',
                'lots' => 0,
                'value' => '0.00',
            ];
        }

        return $buckets;
    }

    /**
     * Blocked working capital at a glance, for a dashboard tile.
     *
     * @return array{lots: int, value: string}
     */
    public function summary(?Facility $facility = null, int $minimumIdleDays = 90): array
    {
        $report = $this->report($facility, $minimumIdleDays);

        return ['lots' => count($report['rows']), 'value' => $report['total_value']];
    }
}
