<?php

declare(strict_types=1);

namespace App\Domain\Formulation\Services;

use App\Domain\Formulation\DTOs\ScaledBatch;
use App\Domain\Formulation\DTOs\ScaledIngredient;
use App\Domain\Formulation\Models\FormulaIngredient;
use App\Domain\Formulation\Models\FormulaVersion;
use App\Domain\Measurement\Enums\UomDimension;
use App\Domain\Measurement\Exceptions\IncompatibleUnitsException;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Measurement\Services\UnitConversionService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Turns percentages into quantities for a batch of a given size.
 *
 * Fixed lines are batch × percentage. The QS line is whatever is left. An
 * "as required" line has no planned quantity. Each result is also expressed
 * in the material's stock unit where the units allow, so that planning can
 * compare it with what the store holds.
 */
class FormulaScalingService
{
    public function __construct(private readonly UnitConversionService $conversions) {}

    public function scale(FormulaVersion $version, BigDecimal|string|int $batchQuantity, Uom $batchUom): ScaledBatch
    {
        $scale = (int) config('erp.precision.quantity_scale', 6);
        $batch = BigDecimal::of($batchQuantity);

        $version->loadMissing('ingredients.item.stockUom');

        $fixedTotal = BigDecimal::zero();
        $fixedQuantity = BigDecimal::zero();
        $lines = [];
        $qsIndex = null;

        foreach ($version->ingredients as $index => $ingredient) {
            /** @var FormulaIngredient $ingredient */
            $percentage = $ingredient->percentage();
            $quantity = null;

            if ($percentage !== null) {
                $quantity = $batch->multipliedBy($percentage)->dividedBy(100, $scale, RoundingMode::HalfUp);
                $fixedTotal = $fixedTotal->plus($percentage);
                $fixedQuantity = $fixedQuantity->plus($quantity);
            } elseif ($ingredient->is_qs) {
                $qsIndex = $index;
            }

            $lines[$index] = [$ingredient, $percentage, $quantity];
        }

        $qsPercentage = null;

        if ($qsIndex !== null) {
            $qsPercentage = BigDecimal::of(100)->minus($fixedTotal);
            $lines[$qsIndex][1] = $qsPercentage;
            $lines[$qsIndex][2] = $batch->minus($fixedQuantity)->toScale($scale, RoundingMode::HalfUp);
        }

        $scaled = [];

        foreach ($lines as [$ingredient, $percentage, $quantity]) {
            $scaled[] = $this->line($ingredient, $percentage, $quantity, $batchUom);
        }

        return new ScaledBatch(
            formulaVersionId: $version->getKey(),
            batchQuantity: $batch,
            batchUomCode: $batchUom->code,
            lines: $scaled,
            fixedPercentage: $fixedTotal,
            qsPercentage: $qsPercentage,
        );
    }

    private function line(FormulaIngredient $ingredient, ?BigDecimal $percentage, ?BigDecimal $quantity, Uom $batchUom): ScaledIngredient
    {
        $item = $ingredient->item;
        $stockQuantity = null;
        $converted = false;

        $assumedDensity = false;

        if ($quantity !== null) {
            try {
                $stockQuantity = $this->conversions->convert($quantity, $batchUom, $item->stockUom, $item);
                $converted = true;
            } catch (IncompatibleUnitsException) {
                // Mass to volume (or back) with no density on the material:
                // plan at 1 g/ml and flag it, so the requirement is a usable
                // estimate rather than a blank. Anything else — a pack unit,
                // a count — stays unconverted: a planner must not order
                // litres of something stocked by the piece.
                $stockQuantity = $this->assumingUnitDensity($quantity, $batchUom, $item->stockUom);
                $assumedDensity = $stockQuantity !== null;
                $converted = $assumedDensity;
            }
        }

        return new ScaledIngredient(
            lineNo: $ingredient->line_no,
            itemId: $item->getKey(),
            itemCode: $item->code,
            itemName: $item->name,
            inciName: $ingredient->inci_name ?? $item->inci_name,
            grade: $ingredient->grade,
            purpose: $ingredient->purpose,
            percentage: $percentage,
            isQs: $ingredient->is_qs,
            asRequired: $ingredient->isAsRequired(),
            quantity: $quantity,
            batchUomCode: $batchUom->code,
            stockQuantity: $stockQuantity,
            stockUomCode: $item->stockUom->code,
            converted: $converted,
            assumedDensity: $assumedDensity,
        );
    }

    private function assumingUnitDensity(BigDecimal $quantity, Uom $from, Uom $to): ?BigDecimal
    {
        $pair = [$from->dimension, $to->dimension];

        if (! in_array(UomDimension::Mass, $pair, strict: true) || ! in_array(UomDimension::Volume, $pair, strict: true)) {
            return null;
        }

        if ($from->needsItemFactor() || $to->needsItemFactor()) {
            return null;
        }

        $scale = (int) config('erp.precision.quantity_scale', 6);

        // 1 g = 1 ml: to the base of one dimension is the base of the other.
        return $quantity->multipliedBy($from->factorToBase())
            ->dividedBy($to->factorToBase(), $scale, RoundingMode::HalfUp);
    }
}
