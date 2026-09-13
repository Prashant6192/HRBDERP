<?php

declare(strict_types=1);

namespace App\Domain\Contract\Services;

use App\Domain\Contract\Models\Client;
use App\Domain\Manufacturing\Enums\ManufacturingOrderStatus;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Manufacturing\Models\ManufacturingOrderAdjustment;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Which third-party customers are actually profitable.
 *
 * For every completed client batch: what was charged (material charge,
 * manufacturing charge, testing, development, artwork, freight, other),
 * what it cost the company (its own raw material and packaging at the
 * consumed lot cost, wastage valued at standard), and the margin. Rolled
 * up per client and per product.
 */
class ClientProfitabilityService
{
    public function __construct(private readonly JobCostingService $costing) {}

    /**
     * Every client with completed batches in the period, most profitable
     * first.
     *
     * @return list<array<string, mixed>>
     */
    public function byClient(int $days = 365, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();

        $orders = ManufacturingOrder::query()
            ->where('manufacturing_type', 'third_party')
            ->where('status', ManufacturingOrderStatus::Completed->value)
            ->where('completed_at', '>=', $asOf->subDays($days))
            ->whereNotNull('client_id')
            ->with(['client:id,code,name', 'product:id,code,name'])
            ->orderByDesc('completed_at')
            ->get();

        return $orders->groupBy('client_id')->map(function (Collection $batches): array {
            $rows = $batches->map(fn (ManufacturingOrder $o) => $this->batch($o));
            $totals = $this->rollUp($rows);
            /** @var ManufacturingOrder $first */
            $first = $batches->first();

            return [
                'client_id' => $first->client_id,
                'code' => $first->client?->code,
                'name' => $first->client?->name,
                'batches' => $batches->count(),
                ...$totals,
                'products' => $rows->groupBy('product_id')->map(fn (Collection $group) => [
                    'product_id' => $group->first()['product_id'],
                    'product' => $group->first()['product'],
                    'batches' => $group->count(),
                    ...$this->rollUp($group),
                ])->sortByDesc(fn (array $p) => (float) $p['margin'])->values()->all(),
                'recent' => $rows->take(10)->values()->all(),
            ];
        })
            ->sortByDesc(fn (array $c) => (float) $c['margin'])
            ->values()
            ->all();
    }

    /**
     * One client's batches in full.
     *
     * @return array<string, mixed>
     */
    public function forClient(Client $client, int $days = 365, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();

        $batches = ManufacturingOrder::query()
            ->where('client_id', $client->id)
            ->where('status', ManufacturingOrderStatus::Completed->value)
            ->where('completed_at', '>=', $asOf->subDays($days))
            ->with(['product:id,code,name'])
            ->orderByDesc('completed_at')
            ->get()
            ->map(fn (ManufacturingOrder $o) => $this->batch($o));

        return [
            'days' => $days,
            'batches' => $batches->count(),
            ...$this->rollUp($batches),
            'products' => $batches->groupBy('product_id')->map(fn (Collection $group) => [
                'product_id' => $group->first()['product_id'],
                'product' => $group->first()['product'],
                'batches' => $group->count(),
                ...$this->rollUp($group),
            ])->sortByDesc(fn (array $p) => (float) $p['margin'])->values()->all(),
            'rows' => $batches->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function batch(ManufacturingOrder $order): array
    {
        $costing = $this->costing->forOrder($order);
        $order->loadMissing(['adjustments', 'lines.item:id,standard_cost']);

        $wastage = $order->adjustments
            ->where('kind', ManufacturingOrderAdjustment::WASTAGE)
            ->reduce(function (BigDecimal $c, ManufacturingOrderAdjustment $a) use ($order): BigDecimal {
                $cost = BigDecimal::of($order->lines->firstWhere('item_id', $a->item_id)?->item?->standard_cost ?? '0');

                return $c->plus(BigDecimal::of($a->quantity)->multipliedBy($cost));
            }, BigDecimal::zero());

        $revenue = BigDecimal::of($costing['chargeable']);
        $rm = BigDecimal::of($costing['raw_material_cost']);
        $pm = BigDecimal::of($costing['packaging_cost']);
        $manufacturing = BigDecimal::of($costing['manufacturing_charge']);
        $terms = $costing['terms'];
        $testing = BigDecimal::of($terms['testing']);
        $freight = BigDecimal::of($terms['freight']);
        $otherRevenue = BigDecimal::of($terms['development'])->plus(BigDecimal::of($terms['artwork']))->plus(BigDecimal::of($terms['other']));
        $cost = $rm->plus($pm)->plus($wastage);
        $margin = $revenue->minus($cost);
        $marginPct = $revenue->isPositive() ? $margin->multipliedBy(100)->dividedBy($revenue, 1, RoundingMode::HalfUp) : null;

        $money = fn (BigDecimal $v): string => (string) $v->toScale(2, RoundingMode::HalfUp);

        return [
            'order_id' => $order->id,
            'number' => $order->number,
            'product_id' => $order->product_id,
            'product' => $order->product?->name,
            'completed_at' => $order->completed_at?->toDateString(),
            'units' => $order->output_units,
            'has_terms' => $costing['has_terms'],
            'revenue' => $money($revenue),
            'material_charge' => $costing['material_charge'],
            'manufacturing_charge' => $money($manufacturing),
            'testing' => $money($testing),
            'freight' => $money($freight),
            'other_revenue' => $money($otherRevenue),
            'raw_material_cost' => $money($rm),
            'packaging_cost' => $money($pm),
            'wastage_cost' => $money($wastage),
            'client_material_value' => $costing['client_material_value'],
            'cost' => $money($cost),
            'margin' => $money($margin),
            'margin_percent' => $marginPct === null ? null : (string) $marginPct,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, string|null>
     */
    private function rollUp(Collection $rows): array
    {
        $sum = fn (string $key) => $rows->reduce(fn (BigDecimal $c, array $r) => $c->plus(BigDecimal::of($r[$key] ?? '0')), BigDecimal::zero());
        $revenue = $sum('revenue');
        $margin = $sum('margin');
        $money = fn (BigDecimal $v): string => (string) $v->toScale(2, RoundingMode::HalfUp);

        return [
            'revenue' => $money($revenue),
            'manufacturing_charge' => $money($sum('manufacturing_charge')),
            'testing' => $money($sum('testing')),
            'freight' => $money($sum('freight')),
            'raw_material_cost' => $money($sum('raw_material_cost')),
            'packaging_cost' => $money($sum('packaging_cost')),
            'wastage_cost' => $money($sum('wastage_cost')),
            'cost' => $money($sum('cost')),
            'margin' => $money($margin),
            'margin_percent' => $revenue->isPositive() ? (string) $margin->multipliedBy(100)->dividedBy($revenue, 1, RoundingMode::HalfUp) : null,
        ];
    }
}
