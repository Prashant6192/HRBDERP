<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Services;

use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Warehousing\Models\Facility;
use App\Support\Math\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Will a lot be used before it expires?
 *
 * For each lot expiring within the risk window, the expected consumption
 * before expiry is the item's daily rate over the days left, less the
 * earlier-expiring lots of the same item that would be used first
 * (first-expiry, first-out). Whatever is left over is at risk, valued at
 * the lot's cost. The sentence a manager reads is the one the figures
 * make: "will expire in 74 days; expected consumption before expiry is
 * only 40%; ₹82,000 at risk".
 */
class ExpiryRiskService
{
    public function __construct(private readonly ConsumptionRateService $rates) {}

    /**
     * @return array{window_days: int, lots: int, at_risk_lots: int, at_risk_value: string, rows: list<array<string, mixed>>}
     */
    public function report(?Facility $facility = null, ?int $windowDays = null, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();
        $today = $asOf->startOfDay();
        $windowDays ??= (int) config('erp.intelligence.expiry_risk_days', 180);
        $storeIds = $facility?->stores()->pluck('id')->all();

        $lots = DB::table('stock_balances as b')
            ->join('inventory_lots as l', 'l.id', '=', 'b.lot_id')
            ->join('items as i', 'i.id', '=', 'l.item_id')
            ->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->leftJoin('uoms as u', 'u.id', '=', 'i.stock_uom_id')
            ->where('b.on_hand', '>', 0)
            ->whereNotNull('l.expiry_at')
            ->where('l.expiry_at', '<=', $today->addDays($windowDays)->toDateString())
            ->whereNotIn('l.qc_status', [LotQcStatus::Rejected->value])
            ->when($storeIds !== null, fn ($q) => $q->whereIn('b.warehouse_id', $storeIds))
            ->selectRaw('l.id AS lot_id, l.batch_number, l.expiry_at, l.unit_cost, l.qc_status, l.owner_client_id, i.id AS item_id, i.code, i.name, i.type, i.standard_cost, u.code AS unit, SUM(b.on_hand) AS on_hand, BOOL_OR(w.is_quarantine) AS in_quarantine')
            ->groupBy('l.id', 'l.batch_number', 'l.expiry_at', 'l.unit_cost', 'l.qc_status', 'l.owner_client_id', 'i.id', 'i.code', 'i.name', 'i.type', 'i.standard_cost', 'u.code')
            ->orderBy('l.expiry_at')
            ->get();

        if ($lots->isEmpty()) {
            return ['window_days' => $windowDays, 'lots' => 0, 'at_risk_lots' => 0, 'at_risk_value' => '0.00', 'rows' => []];
        }

        $itemIds = $lots->pluck('item_id')->unique()->all();
        $rates = $this->rates->ratesFor($itemIds, $storeIds, $asOf);

        // Earlier-expiring stock of the same item is used first, including
        // lots outside the window that expire sooner than this one cannot
        // exist (the window is by expiry), so the queue is the window itself.
        $queued = [];

        $rows = $lots->map(function ($lot) use (&$queued, $rates, $today): array {
            $itemId = (int) $lot->item_id;
            $onHand = BigDecimal::of($lot->on_hand);
            $expiry = CarbonImmutable::parse($lot->expiry_at)->startOfDay();
            $daysLeft = max(0, (int) $today->diffInDays($expiry, false));
            $rate = $rates->get($itemId)['daily_rate'] ?? BigDecimal::zero();

            $ahead = $queued[$itemId] ?? BigDecimal::zero();
            $canUse = $rate->multipliedBy($daysLeft)->minus($ahead);
            $expected = $canUse->isNegative() ? BigDecimal::zero() : ($canUse->isGreaterThan($onHand) ? $onHand : $canUse);
            $queued[$itemId] = $ahead->plus($onHand);

            $atRiskQty = $onHand->minus($expected);
            $coverage = $onHand->isPositive() ? $expected->multipliedBy(100)->dividedBy($onHand, 1, RoundingMode::HalfUp) : BigDecimal::zero();
            $unitCost = $lot->unit_cost ?? $lot->standard_cost;
            $atRiskValue = $unitCost === null ? null : $atRiskQty->multipliedBy(BigDecimal::of($unitCost))->toScale(2, RoundingMode::HalfUp);

            $level = match (true) {
                $daysLeft === 0 => 'expired',
                $coverage->isLessThan(50) => 'high',
                $coverage->isLessThan(100) => 'medium',
                default => 'low',
            };

            return [
                'lot_id' => (int) $lot->lot_id,
                'batch_number' => $lot->batch_number,
                'item_id' => $itemId,
                'code' => $lot->code,
                'name' => $lot->name,
                'type' => $lot->type,
                'unit' => $lot->unit,
                'client_owned' => $lot->owner_client_id !== null,
                'in_quarantine' => (bool) $lot->in_quarantine,
                'on_hand' => (string) $onHand,
                'expiry_at' => $expiry->toDateString(),
                'days_left' => $daysLeft,
                'daily_rate' => (string) $rate->toScale(6, RoundingMode::HalfUp),
                'expected_use' => Decimal::strip($expected, 6),
                'coverage_percent' => (string) $coverage,
                'at_risk_quantity' => Decimal::strip($atRiskQty, 6),
                'at_risk_value' => $atRiskValue === null ? null : (string) $atRiskValue,
                'level' => $level,
                'sentence' => $this->sentence($lot->name, $lot->batch_number, $daysLeft, $coverage, $atRiskValue, $lot->unit, $atRiskQty),
            ];
        })
            ->sortBy([
                fn (array $a, array $b) => ['expired' => 0, 'high' => 1, 'medium' => 2, 'low' => 3][$a['level']] <=> ['expired' => 0, 'high' => 1, 'medium' => 2, 'low' => 3][$b['level']],
                fn (array $a, array $b) => BigDecimal::of($b['at_risk_value'] ?? '0')->compareTo(BigDecimal::of($a['at_risk_value'] ?? '0')),
                fn (array $a, array $b) => $a['days_left'] <=> $b['days_left'],
            ])
            ->values();

        $atRisk = $rows->filter(fn (array $r) => $r['level'] !== 'low');

        return [
            'window_days' => $windowDays,
            'lots' => $rows->count(),
            'at_risk_lots' => $atRisk->count(),
            'at_risk_value' => (string) $atRisk->reduce(fn (BigDecimal $c, array $r) => $c->plus(BigDecimal::of($r['at_risk_value'] ?? '0')), BigDecimal::zero())->toScale(2, RoundingMode::HalfUp),
            'rows' => $rows->all(),
        ];
    }

    private function sentence(string $name, string $batch, int $daysLeft, BigDecimal $coverage, ?BigDecimal $atRiskValue, ?string $unit, BigDecimal $atRiskQty): string
    {
        $when = $daysLeft === 0 ? 'has expired' : "will expire in {$daysLeft} days";
        $qty = rtrim(rtrim((string) $atRiskQty->toScale(3, RoundingMode::HalfUp), '0'), '.');

        if ($coverage->isGreaterThanOrEqualTo(100)) {
            return "{$name} lot {$batch} {$when}; at the current rate it will be used up in time.";
        }

        $money = $atRiskValue === null ? '' : ' ₹'.number_format($atRiskValue->toFloat(), 0, '.', ',').' of inventory at risk.';

        return "{$name} lot {$batch} {$when}; expected consumption before expiry is only {$coverage}%. {$qty} {$unit} likely unused.{$money}";
    }
}
