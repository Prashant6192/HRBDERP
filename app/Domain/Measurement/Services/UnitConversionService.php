<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Services;

use App\Domain\MasterData\Models\Item;
use App\Domain\Measurement\Enums\UomDimension;
use App\Domain\Measurement\Exceptions\IncompatibleUnitsException;
use App\Domain\Measurement\Models\Uom;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The one place in the ERP that converts a quantity from one unit to another.
 *
 * Nothing else may multiply by 1000 to turn kilograms into grams. Conversion
 * factors scattered through controllers and jobs are how an ERP ends up with
 * two answers to "how much do we have", so every caller comes here.
 *
 * All arithmetic is exact decimal arithmetic through brick/math. Quantities
 * are accepted as strings, integers or BigDecimal — never as floats, which is
 * enforced by the parameter types under strict_types: 0.1 + 0.2 is not a
 * defensible basis for a stock balance or a batch weight.
 *
 * Resolution order:
 *
 *   1. Same unit                — nothing to do.
 *   2. An item-specific factor  — one carton of *this* bottle holds 24 pieces.
 *   3. Same dimension           — via the dimension's base unit.
 *   4. Mass <-> volume          — only if the item declares a density.
 *   5. Otherwise                — throw. A quantity that cannot be converted
 *                                 must never be silently passed on.
 */
class UnitConversionService
{
    /**
     * Extra digits carried through intermediate steps so that the final
     * rounding, and only the final rounding, decides the last decimal place.
     */
    private const INTERMEDIATE_GUARD_DIGITS = 8;

    public function __construct(private readonly int $scale = 0)
    {
        //
    }

    /**
     * Convert a quantity between two units.
     *
     * @param  Item|null  $item  Supplies item-specific factors and density.
     */
    public function convert(
        BigDecimal|string|int $quantity,
        Uom $from,
        Uom $to,
        ?Item $item = null,
    ): BigDecimal {
        $value = BigDecimal::of($quantity);

        if ($from->is($to)) {
            return $this->round($value);
        }

        if ($item !== null) {
            $factor = $this->itemSpecificFactor($item, $from, $to);

            if ($factor !== null) {
                return $this->round($value->multipliedBy($factor));
            }
        }

        // A pack unit has no size of its own, so once the item-specific
        // lookup above has come up empty there is nothing left to fall back
        // on. Converting through the dimension base would treat a carton as
        // one piece.
        if ($from->needsItemFactor() || $to->needsItemFactor()) {
            throw IncompatibleUnitsException::missingItemFactor($from, $to, $item);
        }

        if ($from->isSameDimensionAs($to)) {
            return $this->round($this->viaBaseUnit($value, $from, $to));
        }

        $density = $this->densityFor($item, $from, $to);

        if ($density !== null) {
            return $this->round($this->viaDensity($value, $from, $to, $density));
        }

        throw IncompatibleUnitsException::between($from, $to);
    }

    /**
     * Convert into the unit an item's stock is held in.
     *
     * Every receipt, issue and adjustment passes through this on its way to
     * the inventory ledger, so that the ledger holds one unit per item.
     */
    public function toStockUom(Item $item, BigDecimal|string|int $quantity, Uom $from): BigDecimal
    {
        return $this->convert($quantity, $from, $item->stockUom, $item);
    }

    /**
     * Whether a conversion is possible, without performing it.
     */
    public function canConvert(Uom $from, Uom $to, ?Item $item = null): bool
    {
        if ($from->is($to)) {
            return true;
        }

        if ($item !== null && $this->itemSpecificFactor($item, $from, $to) !== null) {
            return true;
        }

        if ($from->needsItemFactor() || $to->needsItemFactor()) {
            return false;
        }

        if ($from->isSameDimensionAs($to)) {
            return true;
        }

        return $this->densityFor($item, $from, $to) !== null;
    }

    /**
     * Convert through the dimension's base unit.
     *
     * quantity x (base units per 'from') / (base units per 'to')
     */
    private function viaBaseUnit(BigDecimal $quantity, Uom $from, Uom $to): BigDecimal
    {
        return $quantity
            ->multipliedBy($from->factorToBase())
            ->dividedBy($to->factorToBase(), $this->intermediateScale(), RoundingMode::HalfUp);
    }

    /**
     * Bridge mass and volume using the item's density in grams per millilitre.
     *
     * The dimension base units are chosen to make this exact: mass reduces to
     * grams and volume to millilitres, which is precisely what a g/ml density
     * relates.
     */
    private function viaDensity(BigDecimal $quantity, Uom $from, Uom $to, BigDecimal $density): BigDecimal
    {
        $inBase = $quantity->multipliedBy($from->factorToBase());

        $convertedBase = $from->dimension === UomDimension::Mass
            // grams / (grams per millilitre) = millilitres
            ? $inBase->dividedBy($density, $this->intermediateScale(), RoundingMode::HalfUp)
            // millilitres x (grams per millilitre) = grams
            : $inBase->multipliedBy($density);

        return $convertedBase->dividedBy(
            $to->factorToBase(),
            $this->intermediateScale(),
            RoundingMode::HalfUp,
        );
    }

    /**
     * A factor defined for this item alone, in either direction.
     */
    private function itemSpecificFactor(Item $item, Uom $from, Uom $to): ?BigDecimal
    {
        $conversions = $item->relationLoaded('uomConversions')
            ? $item->uomConversions
            : $item->uomConversions()->get();

        foreach ($conversions as $conversion) {
            if ($conversion->from_uom_id === $from->getKey() && $conversion->to_uom_id === $to->getKey()) {
                return BigDecimal::of($conversion->factor);
            }
        }

        // The reverse direction is the reciprocal, so a single row defines
        // both ways round and nobody has to remember to enter it twice.
        foreach ($conversions as $conversion) {
            if ($conversion->from_uom_id === $to->getKey() && $conversion->to_uom_id === $from->getKey()) {
                return BigDecimal::one()->dividedBy(
                    BigDecimal::of($conversion->factor),
                    $this->intermediateScale(),
                    RoundingMode::HalfUp,
                );
            }
        }

        return null;
    }

    /**
     * The item's density, but only when it is actually the missing link
     * between these two units.
     */
    private function densityFor(?Item $item, Uom $from, Uom $to): ?BigDecimal
    {
        if ($item?->density_g_per_ml === null) {
            return null;
        }

        $bridged = [UomDimension::Mass, UomDimension::Volume];

        if (! in_array($from->dimension, $bridged, strict: true)) {
            return null;
        }

        if (! in_array($to->dimension, $bridged, strict: true)) {
            return null;
        }

        return BigDecimal::of($item->density_g_per_ml);
    }

    private function round(BigDecimal $value): BigDecimal
    {
        return $value->toScale($this->quantityScale(), RoundingMode::HalfUp);
    }

    private function quantityScale(): int
    {
        return $this->scale > 0
            ? $this->scale
            : (int) config('erp.precision.quantity_scale', 6);
    }

    private function intermediateScale(): int
    {
        return $this->quantityScale() + self::INTERMEDIATE_GUARD_DIGITS;
    }
}
