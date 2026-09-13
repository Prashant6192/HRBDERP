<?php

declare(strict_types=1);

namespace App\Domain\Contract\Services;

use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Manufacturing\Models\ManufacturingOrderLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * What a third-party job cost and what the client is charged for it.
 *
 * Material cost is the batch's actual consumption valued at the batch's
 * lot cost (the item's standard cost where a lot carries none). Material
 * the client supplied is valued the same way but never charged; it is
 * shown so a reconciliation statement can carry a value. The manufacturing
 * charge and the other charges come from the terms recorded on the order.
 * Nothing here is mixed into own-brand costing.
 */
class JobCostingService
{
    public const array RATE_BASES = ['per_quantity', 'per_unit'];

    /**
     * @return array<string, mixed>
     */
    public function forOrder(ManufacturingOrder $order): array
    {
        $order->loadMissing(['lines.item', 'plannedUom', 'reservations.lot', 'reservations.item']);

        $terms = is_array($order->charges) ? $order->charges : [];
        $clientItems = $order->clientSuppliedItemIds();
        $kinds = $order->lines->keyBy('item_id')->map(fn (ManufacturingOrderLine $l) => $l->store_kind->value);

        // Planned: what the recipe says, at standard cost.
        $estimate = ['raw_material' => BigDecimal::zero(), 'packaging' => BigDecimal::zero(), 'client' => BigDecimal::zero()];

        foreach ($order->lines as $line) {
            $value = BigDecimal::of($line->item->standard_cost ?? '0')->multipliedBy(BigDecimal::of($line->planned_quantity ?? '0'));
            $bucket = in_array($line->item_id, $clientItems, true) ? 'client' : $line->store_kind->value;
            $estimate[$bucket] = $estimate[$bucket]->plus($value);
        }

        // Actual: what the kettle and the packing line took, lot by lot.
        $actual = ['raw_material' => BigDecimal::zero(), 'packaging' => BigDecimal::zero(), 'client' => BigDecimal::zero()];
        $consumedAnything = false;

        foreach ($order->reservations as $reservation) {
            /** @var StockReservation $reservation */
            $consumed = BigDecimal::of($reservation->consumed_quantity ?? '0');

            if (! $consumed->isPositive()) {
                continue;
            }

            $consumedAnything = true;
            $unit = $reservation->lot?->unit_cost ?? $reservation->item?->standard_cost ?? '0';
            $value = $consumed->multipliedBy(BigDecimal::of($unit));

            $bucket = $reservation->lot?->owner_client_id !== null && $reservation->lot->owner_client_id === $order->client_id
                ? 'client'
                : ($kinds->get($reservation->item_id) ?? 'raw_material');

            $actual[$bucket] = $actual[$bucket]->plus($value);
        }

        $basis = $consumedAnything ? 'actual' : 'estimate';
        $materials = $consumedAnything ? $actual : $estimate;

        $rawMaterial = $materials['raw_material'];
        $packaging = $materials['packaging'];
        $clientMaterial = $materials['client'];
        $companyMaterial = $rawMaterial->plus($packaging);

        // The terms.
        $rate = $this->number($terms['manufacturing_rate'] ?? null);
        $rateBasis = in_array($terms['rate_basis'] ?? null, self::RATE_BASES, strict: true) ? $terms['rate_basis'] : 'per_quantity';
        $billMaterials = (bool) ($terms['bill_materials'] ?? true);
        $markup = $this->number($terms['material_markup_pct'] ?? null);
        $gstRate = $this->number($terms['gst_rate'] ?? 18);

        $quantity = $order->output_quantity !== null ? BigDecimal::of($order->output_quantity) : $order->plannedQuantity();
        $units = BigDecimal::of($order->output_units ?? $order->planned_units ?? 0);
        $manufacturingCharge = $rateBasis === 'per_unit' ? $rate->multipliedBy($units) : $rate->multipliedBy($quantity);

        $other = [];

        foreach (['testing', 'development', 'artwork', 'freight', 'other'] as $key) {
            $other[$key] = $this->number($terms[$key] ?? null);
        }

        $otherCharges = array_reduce($other, fn (BigDecimal $c, BigDecimal $v) => $c->plus($v), BigDecimal::zero());

        $materialCharge = $billMaterials
            ? $companyMaterial->multipliedBy(BigDecimal::of(100)->plus($markup))->dividedBy(100, 6, RoundingMode::HalfUp)
            : BigDecimal::zero();

        $chargeable = $materialCharge->plus($manufacturingCharge)->plus($otherCharges);
        $gst = $chargeable->multipliedBy($gstRate)->dividedBy(100, 6, RoundingMode::HalfUp);
        $total = $chargeable->plus($gst);

        // Margin before conversion cost, which the ERP does not yet track:
        // what is charged over what the company's own material cost.
        $margin = $chargeable->minus($companyMaterial);

        $money = fn (BigDecimal $v): string => $v->toScale(2, RoundingMode::HalfUp)->__toString();

        return [
            'has_terms' => $terms !== [],
            'basis' => $basis,
            'terms' => [
                'manufacturing_rate' => $money($rate),
                'rate_basis' => $rateBasis,
                'rate_unit' => $rateBasis === 'per_unit' ? 'unit' : ($order->plannedUom?->code ?? 'unit'),
                'bill_materials' => $billMaterials,
                'material_markup_pct' => $markup->toScale(2, RoundingMode::HalfUp)->__toString(),
                'gst_rate' => $gstRate->toScale(2, RoundingMode::HalfUp)->__toString(),
                'testing' => $money($other['testing']),
                'development' => $money($other['development']),
                'artwork' => $money($other['artwork']),
                'freight' => $money($other['freight']),
                'other' => $money($other['other']),
                'notes' => $terms['notes'] ?? null,
            ],
            'estimated_material_cost' => $money($estimate['raw_material']->plus($estimate['packaging'])),
            'raw_material_cost' => $money($rawMaterial),
            'packaging_cost' => $money($packaging),
            'company_material_cost' => $money($companyMaterial),
            'client_material_value' => $money($clientMaterial),
            'material_charge' => $money($materialCharge),
            'manufacturing_charge' => $money($manufacturingCharge),
            'charged_quantity' => $rateBasis === 'per_unit' ? $units->__toString() : $quantity->__toString(),
            'other_charges' => $money($otherCharges),
            'chargeable' => $money($chargeable),
            'gst' => $money($gst),
            'total' => $money($total),
            'margin' => $money($margin),
        ];
    }

    private function number(mixed $value): BigDecimal
    {
        if ($value === null || $value === '' || ! is_numeric((string) $value)) {
            return BigDecimal::zero();
        }

        return BigDecimal::of((string) $value);
    }
}
