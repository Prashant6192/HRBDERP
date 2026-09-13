<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Services;

use App\Domain\Formulation\Models\Formula;
use App\Domain\MasterData\Models\Item;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Planning\DTOs\RequirementLine;
use App\Domain\Planning\Services\ProductionRequirementService;
use App\Domain\Warehousing\Models\Facility;
use App\Support\Math\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * "What if we manufacture 20,000 bottles of Product X?"
 *
 * Without raising a plan: the raw material and packaging it would need,
 * what is short, what the shortfall would cost to buy, the expected
 * material cost, the machine time, the completion date, and which booked
 * batches it would push. Nothing is written.
 */
class WhatIfService
{
    public function __construct(
        private readonly ProductionRequirementService $requirements,
        private readonly StockOutlookService $outlook,
        private readonly CapacityService $capacity,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function simulate(Formula $formula, BigDecimal|string $quantity, Uom $uom, ?Facility $facility, ?CarbonImmutable $startFrom = null, ?int $clientId = null): array
    {
        $formula->loadMissing(['activeVersion.batchUom', 'product.netContentUom', 'product.stockUom', 'client:id,name']);
        $version = $formula->activeVersion;

        if ($version === null) {
            return ['error' => "{$formula->name} has no active version to simulate."];
        }

        $quantity = BigDecimal::of($quantity);
        $result = $this->requirements->calculate($version, $quantity, $uom, $formula->product, $facility, $clientId ?? $formula->client_id);
        $lines = $result->lines();
        $itemIds = array_map(fn (RequirementLine $l) => $l->itemId, $lines);
        $items = Item::query()->whereIn('id', $itemIds)->get(['id', 'standard_cost', 'lead_time_days'])->keyBy('id');
        $vendors = $this->outlook->vendorAdvice($itemIds);

        $materialCost = BigDecimal::zero();
        $purchaseValue = BigDecimal::zero();
        $longestLead = 0;
        $rows = [];

        foreach ($lines as $line) {
            $item = $items->get($line->itemId);
            $standard = BigDecimal::of($item?->standard_cost ?? '0');
            $vendor = $vendors->get($line->itemId);
            $buyPrice = $vendor !== null && $vendor['best_price'] !== null ? BigDecimal::of($vendor['best_price']) : $standard;

            $cost = $line->required->multipliedBy($standard);
            $buy = $line->shortage->multipliedBy($buyPrice);
            $materialCost = $materialCost->plus($cost);
            $purchaseValue = $purchaseValue->plus($buy);

            if ($line->isShort()) {
                $lead = (int) ($item?->lead_time_days ?? $vendor['lead_time_days'] ?? config('erp.intelligence.default_lead_time_days', 7));
                $longestLead = max($longestLead, $lead);
            }

            $rows[] = [
                ...$line->toArray(),
                'standard_cost' => (string) $standard->toScale(2, RoundingMode::HalfUp),
                'material_cost' => (string) $cost->toScale(2, RoundingMode::HalfUp),
                'buy_price' => (string) $buyPrice->toScale(2, RoundingMode::HalfUp),
                'purchase_value' => (string) $buy->toScale(2, RoundingMode::HalfUp),
                'vendor' => $vendor['name'] ?? null,
                'lead_time_days' => $line->isShort() ? (int) ($item?->lead_time_days ?? $vendor['lead_time_days'] ?? config('erp.intelligence.default_lead_time_days', 7)) : null,
            ];
        }

        $kg = $this->capacity->toKg($quantity, $uom, $formula->product);
        $earliestStart = ($startFrom ?? CarbonImmutable::now())->startOfDay();

        if ($longestLead > 0) {
            $materialsBy = CarbonImmutable::now()->startOfDay()->addDays($longestLead);
            $earliestStart = $earliestStart->greaterThan($materialsBy) ? $earliestStart : $materialsBy;
        }

        $fit = $facility !== null ? $this->capacity->fit($facility, $kg, $earliestStart) : null;
        $units = $result->units;
        $perUnit = $units !== null && $units > 0 ? $materialCost->dividedBy($units, 2, RoundingMode::HalfUp) : null;

        $short = array_values(array_filter($rows, fn (array $r) => BigDecimal::of($r['shortage'])->isPositive()));

        $summary = [];
        $summary[] = 'Making '.Decimal::strip($quantity).' '.$uom->code.' of '.($formula->product?->name ?? $formula->name).($units ? " fills about {$units} units." : '.');
        $summary[] = count($short) === 0
            ? 'Every material is in stock.'
            : count($short).' material'.(count($short) === 1 ? ' is' : 's are').' short; buying the shortfall would cost about ₹'.number_format($purchaseValue->toFloat(), 0).' and take up to '.$longestLead.' days to arrive.';
        $summary[] = 'Expected material cost ₹'.number_format($materialCost->toFloat(), 0).($perUnit ? ' (₹'.$perUnit.' per unit)' : '').' at standard cost.';

        if ($fit !== null) {
            $summary[] = $fit['capacity_kg'] === null
                ? $fit['note']
                : 'Machine time about '.$fit['days_needed'].' day'.($fit['days_needed'] === '1' ? '' : 's').' at '.$facility->name.'; could start '.CarbonImmutable::parse($fit['start'])->format('j M').' and finish '.CarbonImmutable::parse($fit['completion'])->format('j M').'.'.($fit['note'] ? ' '.$fit['note'] : ' Nothing else would move.');
        }

        return [
            'formula' => ['id' => $formula->id, 'code' => $formula->code, 'name' => $formula->name, 'product' => $formula->product?->name, 'client' => $formula->client?->name, 'version' => $version->version_number],
            'quantity' => Decimal::strip($quantity),
            'uom' => $uom->code,
            'kg' => Decimal::strip($kg, 3),
            'units' => $units,
            'raw_materials' => array_values(array_filter($rows, fn (array $r) => $r['store_kind'] === 'raw_material')),
            'packaging' => array_values(array_filter($rows, fn (array $r) => $r['store_kind'] === 'packaging')),
            'short' => $short,
            'material_cost' => (string) $materialCost->toScale(2, RoundingMode::HalfUp),
            'cost_per_unit' => $perUnit === null ? null : (string) $perUnit,
            'purchase_value' => (string) $purchaseValue->toScale(2, RoundingMode::HalfUp),
            'longest_lead_days' => $longestLead,
            'earliest_start' => $earliestStart->toDateString(),
            'fit' => $fit,
            'warnings' => $result->warnings,
            'summary' => $summary,
        ];
    }
}
