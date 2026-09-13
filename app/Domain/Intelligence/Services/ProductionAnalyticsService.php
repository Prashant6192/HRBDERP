<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Services;

use App\Domain\Manufacturing\Enums\ManufacturingOrderStatus;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Manufacturing\Models\ManufacturingOrderAdjustment;
use App\Domain\Manufacturing\Models\ManufacturingOrderLine;
use App\Domain\Warehousing\Models\Facility;
use App\Support\Math\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Material consumption intelligence, yield analytics and the cost variance
 * engine — for one batch and across batches.
 *
 * Standard is what the recipe said for the batch. Issued is what the store
 * handed over. Consumed is what the kettle and the packing line took (the
 * ledger's word). Returned is what came back. Wastage is what was lost.
 * Actual use is consumed less returned; the variance is actual use against
 * standard.
 */
class ProductionAnalyticsService
{
    /**
     * Standard vs issued vs consumed vs returned vs wastage, per material,
     * for one batch.
     *
     * @return array{lines: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function consumptionForOrder(ManufacturingOrder $order): array
    {
        $order->loadMissing(['lines.item:id,code,name,standard_cost', 'lines.uom:id,code', 'adjustments']);

        $adjustments = $order->adjustments->groupBy('item_id');

        $lines = $order->lines->map(function (ManufacturingOrderLine $line) use ($adjustments): array {
            $standard = BigDecimal::of($line->planned_quantity ?? '0');
            $issued = BigDecimal::of($line->reserved_quantity ?? '0');
            $consumed = BigDecimal::of($line->consumed_quantity ?? '0');
            $mine = $adjustments->get($line->item_id) ?? collect();
            $returned = $mine->where('kind', ManufacturingOrderAdjustment::RETURN)->reduce(fn (BigDecimal $c, $a) => $c->plus(BigDecimal::of($a->quantity)), BigDecimal::zero());
            $wastage = $mine->where('kind', ManufacturingOrderAdjustment::WASTAGE)->reduce(fn (BigDecimal $c, $a) => $c->plus(BigDecimal::of($a->quantity)), BigDecimal::zero());
            $actual = $consumed->minus($returned);
            $variance = $actual->minus($standard);
            $variancePct = $standard->isPositive() ? $variance->multipliedBy(100)->dividedBy($standard, 1, RoundingMode::HalfUp) : null;

            return [
                'item_id' => $line->item_id,
                'code' => $line->item->code,
                'name' => $line->item->name,
                'store_kind' => $line->store_kind->value,
                'unit' => $line->uom?->code,
                'standard' => Decimal::strip($standard),
                'issued' => Decimal::strip($issued),
                'consumed' => Decimal::strip($consumed),
                'returned' => Decimal::strip($returned),
                'wastage' => Decimal::strip($wastage),
                'actual' => Decimal::strip($actual),
                'variance' => Decimal::strip($variance),
                'variance_percent' => $variancePct === null ? null : (string) $variancePct,
                'flag' => $variancePct !== null && abs($variancePct->toFloat()) >= (float) config('erp.exceptions.material_variance_percent', 5),
            ];
        })->values()->all();

        $sum = fn (string $key) => array_reduce($lines, fn (BigDecimal $c, array $l) => $c->plus(BigDecimal::of($l[$key])), BigDecimal::zero());

        return [
            'lines' => $lines,
            'totals' => [
                'standard' => Decimal::strip($sum('standard')),
                'issued' => Decimal::strip($sum('issued')),
                'consumed' => Decimal::strip($sum('consumed')),
                'returned' => Decimal::strip($sum('returned')),
                'wastage' => Decimal::strip($sum('wastage')),
                'actual' => Decimal::strip($sum('actual')),
            ],
        ];
    }

    /**
     * Per material, how consumption has run against standard over the last
     * N completed batches: "Preservative consumption has been 4.8% above
     * standard in the last six batches."
     *
     * @return list<array<string, mixed>>
     */
    public function consumptionTrends(?Facility $facility = null, int $batches = 6, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();

        $rows = DB::table('manufacturing_order_lines as l')
            ->join('manufacturing_orders as o', 'o.id', '=', 'l.manufacturing_order_id')
            ->join('items as i', 'i.id', '=', 'l.item_id')
            ->leftJoin('uoms as u', 'u.id', '=', 'l.uom_id')
            ->leftJoin(DB::raw("(SELECT manufacturing_order_id, item_id, SUM(CASE WHEN kind = 'return' THEN quantity ELSE 0 END) AS returned, SUM(CASE WHEN kind = 'wastage' THEN quantity ELSE 0 END) AS wasted FROM manufacturing_order_adjustments GROUP BY manufacturing_order_id, item_id) AS a"), function ($join): void {
                $join->on('a.manufacturing_order_id', '=', 'l.manufacturing_order_id')->on('a.item_id', '=', 'l.item_id');
            })
            ->whereNull('o.deleted_at')
            ->where('o.status', ManufacturingOrderStatus::Completed->value)
            ->where('l.planned_quantity', '>', 0)
            ->when($facility, fn ($q) => $q->where('o.facility_id', $facility->id))
            ->selectRaw('l.item_id, i.code, i.name, u.code AS unit, o.id AS order_id, o.number, o.completed_at, l.planned_quantity, l.consumed_quantity, COALESCE(a.returned, 0) AS returned, COALESCE(a.wasted, 0) AS wasted')
            ->orderByDesc('o.completed_at')
            ->get();

        return $rows->groupBy('item_id')->map(function (Collection $all) use ($batches): array {
            $recent = $all->take($batches);
            $standard = $recent->reduce(fn (BigDecimal $c, $r) => $c->plus(BigDecimal::of($r->planned_quantity)), BigDecimal::zero());
            $actual = $recent->reduce(fn (BigDecimal $c, $r) => $c->plus(BigDecimal::of($r->consumed_quantity)->minus(BigDecimal::of($r->returned))), BigDecimal::zero());
            $wasted = $recent->reduce(fn (BigDecimal $c, $r) => $c->plus(BigDecimal::of($r->wasted)), BigDecimal::zero());
            $variance = $standard->isPositive() ? $actual->minus($standard)->multipliedBy(100)->dividedBy($standard, 1, RoundingMode::HalfUp) : BigDecimal::zero();
            $first = $recent->first();
            $n = $recent->count();
            $direction = $variance->isPositive() ? 'above' : ($variance->isNegative() ? 'below' : 'at');

            return [
                'item_id' => (int) $first->item_id,
                'code' => $first->code,
                'name' => $first->name,
                'unit' => $first->unit,
                'batches' => $n,
                'standard' => Decimal::strip($standard),
                'actual' => Decimal::strip($actual),
                'wastage' => Decimal::strip($wasted),
                'variance_percent' => (string) $variance,
                'sentence' => $variance->isZero()
                    ? "{$first->name} consumption has matched standard in the last {$n} batch".($n === 1 ? '' : 'es').'.'
                    : "{$first->name} consumption has been ".Decimal::strip($variance->abs())."% {$direction} standard in the last {$n} batch".($n === 1 ? '' : 'es').'.',
                'per_batch' => $recent->map(fn ($r) => [
                    'order_id' => (int) $r->order_id,
                    'number' => $r->number,
                    'completed_at' => CarbonImmutable::parse($r->completed_at)->toDateString(),
                    'standard' => Decimal::strip($r->planned_quantity),
                    'actual' => Decimal::strip(BigDecimal::of($r->consumed_quantity)->minus(BigDecimal::of($r->returned))),
                    'variance_percent' => BigDecimal::of($r->planned_quantity)->isPositive()
                        ? (string) BigDecimal::of($r->consumed_quantity)->minus(BigDecimal::of($r->returned))->minus(BigDecimal::of($r->planned_quantity))->multipliedBy(100)->dividedBy(BigDecimal::of($r->planned_quantity), 1, RoundingMode::HalfUp)
                        : null,
                ])->values()->all(),
            ];
        })
            ->sortByDesc(fn (array $r) => abs((float) $r['variance_percent']))
            ->values()
            ->all();
    }

    /**
     * Planned yield versus actual per product, with the leakage in units.
     *
     * @return list<array<string, mixed>>
     */
    public function yieldByProduct(?Facility $facility = null, int $days = 90, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();

        $orders = ManufacturingOrder::query()
            ->where('status', ManufacturingOrderStatus::Completed->value)
            ->where('completed_at', '>=', $asOf->subDays($days))
            ->when($facility, fn ($q) => $q->where('facility_id', $facility->id))
            ->with(['product:id,code,name', 'plannedUom:id,code'])
            ->orderByDesc('completed_at')
            ->get();

        return $orders->groupBy(fn (ManufacturingOrder $o) => $o->product_id ?? 0)->map(function (Collection $batches): array {
            /** @var ManufacturingOrder $first */
            $first = $batches->first();
            $plannedQty = $batches->reduce(fn (BigDecimal $c, ManufacturingOrder $o) => $c->plus($o->plannedQuantity()), BigDecimal::zero());
            $outputQty = $batches->reduce(fn (BigDecimal $c, ManufacturingOrder $o) => $c->plus(BigDecimal::of($o->output_quantity ?? '0')), BigDecimal::zero());
            $plannedUnits = $batches->sum(fn (ManufacturingOrder $o) => (int) ($o->planned_units ?? 0));
            $outputUnits = $batches->sum(fn (ManufacturingOrder $o) => (int) ($o->output_units ?? 0));
            $yield = $plannedQty->isPositive() ? $outputQty->multipliedBy(100)->dividedBy($plannedQty, 1, RoundingMode::HalfUp) : BigDecimal::zero();
            $unitVariance = $plannedUnits - $outputUnits;
            $floor = (float) config('erp.exceptions.yield_floor_percent', 95);

            return [
                'product_id' => $first->product_id,
                'code' => $first->product?->code,
                'name' => $first->product?->name ?? 'No product linked',
                'unit' => $first->plannedUom?->code,
                'batches' => $batches->count(),
                'planned_quantity' => Decimal::strip($plannedQty),
                'output_quantity' => Decimal::strip($outputQty),
                'planned_units' => $plannedUnits,
                'output_units' => $outputUnits,
                'yield_percent' => (string) $yield,
                'unit_variance' => $unitVariance,
                'below_target' => $yield->toFloat() < $floor,
                'sentence' => $plannedUnits > 0
                    ? "Expected {$plannedUnits} units, produced {$outputUnits} units — yield {$yield}%, {$unitVariance}-unit variance."
                    : 'Expected '.Decimal::strip($plannedQty).' '.($first->plannedUom?->code ?? '').', produced '.Decimal::strip($outputQty).' — yield '.$yield.'%.',
                'per_batch' => $batches->map(fn (ManufacturingOrder $o) => [
                    'order_id' => $o->id,
                    'number' => $o->number,
                    'completed_at' => $o->completed_at?->toDateString(),
                    'planned_units' => $o->planned_units,
                    'output_units' => $o->output_units,
                    'yield_percent' => $o->yield_percentage === null ? null : Decimal::strip($o->yield_percentage),
                ])->values()->all(),
            ];
        })
            ->sortBy(fn (array $r) => (float) $r['yield_percent'])
            ->values()
            ->all();
    }

    /**
     * Why did this batch cost what it did? Standard cost against actual,
     * explained: material price change, extra consumption, packaging
     * wastage, low yield, additional charges.
     *
     * @return array<string, mixed>
     */
    public function costVariance(ManufacturingOrder $order): array
    {
        $order->loadMissing(['lines.item:id,code,name,standard_cost,type', 'lines.uom:id,code', 'reservations.lot:id,unit_cost', 'adjustments.lot:id,unit_cost']);

        $money = fn (BigDecimal $v): string => (string) $v->toScale(2, RoundingMode::HalfUp);

        // Actual unit cost per material: the weighted lot cost of what was
        // consumed, falling back to the standard cost.
        $lotCost = [];

        foreach ($order->reservations as $r) {
            $consumed = BigDecimal::of($r->consumed_quantity ?? '0');

            if (! $consumed->isPositive()) {
                continue;
            }

            $unit = BigDecimal::of($r->lot?->unit_cost ?? $order->lines->firstWhere('item_id', $r->item_id)?->item?->standard_cost ?? '0');
            $lotCost[$r->item_id] = [
                'qty' => ($lotCost[$r->item_id]['qty'] ?? BigDecimal::zero())->plus($consumed),
                'value' => ($lotCost[$r->item_id]['value'] ?? BigDecimal::zero())->plus($consumed->multipliedBy($unit)),
            ];
        }

        $consumption = $this->consumptionForOrder($order);
        $lines = [];
        $standardTotal = BigDecimal::zero();
        $actualTotal = BigDecimal::zero();
        $priceEffect = BigDecimal::zero();
        $usageEffect = BigDecimal::zero();
        $packagingWaste = BigDecimal::zero();
        $rmWaste = BigDecimal::zero();

        foreach ($consumption['lines'] as $c) {
            $line = $order->lines->firstWhere('item_id', $c['item_id']);
            $standardCost = BigDecimal::of($line?->item?->standard_cost ?? '0');
            $standardQty = BigDecimal::of($c['standard']);
            $actualQty = BigDecimal::of($c['actual']);
            $wastageQty = BigDecimal::of($c['wastage']);

            $actualUnit = isset($lotCost[$c['item_id']]) && $lotCost[$c['item_id']]['qty']->isPositive()
                ? $lotCost[$c['item_id']]['value']->dividedBy($lotCost[$c['item_id']]['qty'], 6, RoundingMode::HalfUp)
                : $standardCost;

            $standardValue = $standardQty->multipliedBy($standardCost);
            $actualValue = $actualQty->multipliedBy($actualUnit);

            // Decompose: (actual unit − standard unit) × actual qty is price;
            // (actual qty − standard qty) × standard unit is usage.
            $price = $actualUnit->minus($standardCost)->multipliedBy($actualQty);
            $usage = $actualQty->minus($standardQty)->multipliedBy($standardCost);
            $waste = $wastageQty->multipliedBy($standardCost);

            $standardTotal = $standardTotal->plus($standardValue);
            $actualTotal = $actualTotal->plus($actualValue);
            $priceEffect = $priceEffect->plus($price);
            $usageEffect = $usageEffect->plus($usage->minus($waste));

            if ($c['store_kind'] === 'packaging') {
                $packagingWaste = $packagingWaste->plus($waste);
            } else {
                $rmWaste = $rmWaste->plus($waste);
            }

            $lines[] = [
                ...$c,
                'standard_unit_cost' => $money($standardCost),
                'actual_unit_cost' => $money($actualUnit),
                'standard_value' => $money($standardValue),
                'actual_value' => $money($actualValue),
                'price_effect' => $money($price),
                'usage_effect' => $money($usage),
            ];
        }

        // Charges beyond material recorded on the order (labour, testing,
        // freight and the rest), when any are.
        $terms = is_array($order->charges) ? $order->charges : [];
        $extra = BigDecimal::zero();

        foreach (['labour', 'testing', 'development', 'artwork', 'freight', 'other'] as $key) {
            if (isset($terms[$key]) && is_numeric((string) $terms[$key])) {
                $extra = $extra->plus(BigDecimal::of((string) $terms[$key]));
            }
        }

        $actualTotal = $actualTotal->plus($extra);

        // Low yield: what each unit cost against what it should have.
        $plannedUnits = (int) ($order->planned_units ?? 0);
        $outputUnits = (int) ($order->output_units ?? 0);
        $standardPerUnit = $plannedUnits > 0 ? $standardTotal->dividedBy($plannedUnits, 4, RoundingMode::HalfUp) : null;
        $actualPerUnit = $outputUnits > 0 ? $actualTotal->dividedBy($outputUnits, 4, RoundingMode::HalfUp) : null;
        $yieldEffect = $standardPerUnit !== null && $outputUnits > 0 && $plannedUnits > $outputUnits
            ? $standardPerUnit->multipliedBy($plannedUnits - $outputUnits)
            : BigDecimal::zero();

        $variance = $actualTotal->minus($standardTotal);

        $reasons = collect([
            ['key' => 'price', 'label' => 'Raw-material price change', 'amount' => $priceEffect, 'text' => 'materials cost more per unit than the standard'],
            ['key' => 'usage', 'label' => 'Extra consumption', 'amount' => $usageEffect, 'text' => 'more material went into the batch than the recipe said'],
            ['key' => 'rm_wastage', 'label' => 'Raw-material wastage', 'amount' => $rmWaste, 'text' => 'material issued and lost'],
            ['key' => 'pm_wastage', 'label' => 'Packaging wastage', 'amount' => $packagingWaste, 'text' => 'packaging spoiled on the line'],
            ['key' => 'extra', 'label' => 'Additional charges', 'amount' => $extra, 'text' => 'labour, testing, freight and other charges recorded on the batch'],
            ['key' => 'yield', 'label' => 'Low yield', 'amount' => $yieldEffect, 'text' => "{$plannedUnits} units were expected and {$outputUnits} came out; the cost is spread over fewer units"],
        ])
            ->filter(fn (array $r) => ! $r['amount']->isZero())
            ->sortByDesc(fn (array $r) => $r['amount']->abs()->toFloat())
            ->map(fn (array $r) => [...$r, 'amount' => $money($r['amount'])])
            ->values()
            ->all();

        $sentence = $variance->isZero()
            ? "{$order->number} cost exactly its standard."
            : "{$order->number} cost ₹".number_format($variance->abs()->toFloat(), 0).' '.($variance->isPositive() ? 'more' : 'less').' than standard'
                .($reasons !== [] ? ', mostly because '.strtolower($reasons[0]['label']).': '.$reasons[0]['text'].'.' : '.');

        return [
            'standard_total' => $money($standardTotal),
            'actual_total' => $money($actualTotal),
            'variance' => $money($variance),
            'variance_percent' => $standardTotal->isPositive() ? (string) $variance->multipliedBy(100)->dividedBy($standardTotal, 1, RoundingMode::HalfUp) : null,
            'standard_per_unit' => $standardPerUnit === null ? null : $money($standardPerUnit),
            'actual_per_unit' => $actualPerUnit === null ? null : $money($actualPerUnit),
            'planned_units' => $plannedUnits ?: null,
            'output_units' => $outputUnits ?: null,
            'reasons' => $reasons,
            'lines' => $lines,
            'sentence' => $sentence,
            'has_actuals' => $order->status === ManufacturingOrderStatus::Completed || $order->status === ManufacturingOrderStatus::InProgress,
        ];
    }
}
