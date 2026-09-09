<?php

declare(strict_types=1);

namespace App\Domain\Planning\Services;

use App\Domain\Formulation\DTOs\ScaledIngredient;
use App\Domain\Formulation\Models\FormulaVersion;
use App\Domain\Formulation\Services\FormulaScalingService;
use App\Domain\Inventory\Enums\StockAlertLevel;
use App\Domain\Inventory\Services\StockAlertService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\MasterData\Models\Item;
use App\Domain\MasterData\Models\Product;
use App\Domain\Measurement\Enums\UomDimension;
use App\Domain\Measurement\Exceptions\IncompatibleUnitsException;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Measurement\Services\UnitConversionService;
use App\Domain\Planning\DTOs\RequirementLine;
use App\Domain\Planning\DTOs\RequirementResult;
use App\Domain\Planning\Enums\StoreKind;
use App\Domain\Planning\Models\ProductPackagingLine;
use App\Domain\Warehousing\Models\Warehouse;
use App\Domain\Warehousing\Services\WarehouseResolver;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * "Can we make this batch?"
 *
 * Scales the recipe to the batch, expresses every material in its stock
 * unit, and sets each against what the raw material store can release for
 * production right now — QC-approved, unexpired, unreserved. The product's
 * packaging list is worked out the same way against the packaging store.
 * Nothing here writes; ProductionPlanService keeps the answer.
 */
class ProductionRequirementService
{
    public function __construct(
        private readonly FormulaScalingService $scaling,
        private readonly StockBalanceService $balances,
        private readonly StockAlertService $alerts,
        private readonly UnitConversionService $conversions,
        private readonly WarehouseResolver $warehouses,
    ) {}

    public function calculate(FormulaVersion $version, BigDecimal|string|int $quantity, Uom $uom, ?Product $product = null): RequirementResult
    {
        $batch = BigDecimal::of($quantity);
        $result = new RequirementResult($batch, $uom->code, null);

        $rmStore = $this->warehouses->findStoreOfType(StoreKind::RawMaterial->warehouseType());

        if ($rmStore === null) {
            $result->warnings[] = 'No raw material store is configured, so availability could not be checked. Create one under Warehouses.';
        }

        $scaled = $this->scaling->scale($version, $batch, $uom);

        foreach ($scaled->lines as $index => $line) {
            $result->rawMaterials[] = $this->rawMaterialLine($line, $rmStore);
        }

        if (! $scaled->isComplete()) {
            $result->warnings[] = "The recipe accounts for {$scaled->fixedPercentage}% of the batch and has no QS line; the remainder is not planned.";
        }

        $this->addPackaging($result, $product, $batch, $uom);

        return $result;
    }

    private function rawMaterialLine(ScaledIngredient $line, ?Warehouse $store): RequirementLine
    {
        $item = Item::with('stockUom')->findOrFail($line->itemId);
        $notes = [];

        if ($line->asRequired) {
            $notes[] = 'Dosed as required at the kettle; not planned as a quantity.';
        }

        if ($line->quantity !== null && $line->stockQuantity === null) {
            $notes[] = "Cannot convert {$line->quantity} {$line->batchUomCode} to the stock unit ({$line->stockUomCode}); set a density or a unit conversion on the material.";
        }

        if ($line->assumedDensity) {
            $notes[] = 'Converted at 1 g/ml because the material has no density on record.';
        }

        $required = $line->stockQuantity ?? BigDecimal::zero();

        return $this->line(StoreKind::RawMaterial, $item, $required, $store, $line->percentage, $line->isQs, $line->asRequired, $notes);
    }

    private function addPackaging(RequirementResult $result, ?Product $product, BigDecimal $batch, Uom $batchUom): void
    {
        if ($product === null) {
            $result->warnings[] = 'The formula is not linked to a product, so packaging cannot be planned. Link a product on the formula.';

            return;
        }

        $product->loadMissing(['netContentUom', 'packagingLines.packagingMaterial.stockUom']);

        if ($product->packagingLines->isEmpty()) {
            $result->warnings[] = "{$product->name} has no packaging list, so no packaging request will be raised. Add its bottle, cap, label and carton on the product screen.";

            return;
        }

        $units = $this->unitsFor($product, $batch, $batchUom, $result);

        if ($units === null) {
            return;
        }

        $result->units = $units;

        $pmStore = $this->warehouses->findStoreOfType(StoreKind::Packaging->warehouseType());

        if ($pmStore === null) {
            $result->warnings[] = 'No packaging store is configured, so packaging availability could not be checked.';
        }

        foreach ($product->packagingLines as $bom) {
            /** @var ProductPackagingLine $bom */
            $material = $bom->packagingMaterial;
            $required = BigDecimal::of($bom->quantity_per_unit)->multipliedBy($units);

            // Packaging is counted in whole pieces; a share of a carton
            // rounds up to the carton.
            if ($material->stockUom->dimension === UomDimension::Count) {
                $required = $required->toScale(0, RoundingMode::Ceiling);
            }

            $result->packaging[] = $this->line(StoreKind::Packaging, $material, $required, $pmStore, null, false, false, []);
        }
    }

    /**
     * How many units of finished product the batch fills.
     */
    private function unitsFor(Product $product, BigDecimal $batch, Uom $batchUom, RequirementResult $result): ?int
    {
        if ($product->net_content === null || $product->netContentUom === null) {
            $result->warnings[] = "{$product->name} has no net content, so the number of units cannot be worked out. Set it on the product screen.";

            return null;
        }

        try {
            $inContentUnit = $this->conversions->convert($batch, $batchUom, $product->netContentUom, $product);
        } catch (IncompatibleUnitsException) {
            $inContentUnit = $this->assumingUnitDensity($batch, $batchUom, $product->netContentUom);

            if ($inContentUnit === null) {
                $result->warnings[] = "Cannot convert {$batch} {$batchUom->code} into {$product->netContentUom->code} for {$product->name}; set the product's density.";

                return null;
            }

            $result->warnings[] = "Units were worked out at 1 g/ml because {$product->name} has no density on record.";
        }

        $units = $inContentUnit->dividedBy(BigDecimal::of($product->net_content), 0, RoundingMode::Down);

        return $units->toInt();
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

        return $quantity->multipliedBy($from->factorToBase())->dividedBy($to->factorToBase(), 6, RoundingMode::HalfUp);
    }

    /**
     * @param  list<string>  $notes
     */
    private function line(StoreKind $kind, Item $item, BigDecimal $required, ?Warehouse $store, ?BigDecimal $percentage, bool $isQs, bool $asRequired, array $notes): RequirementLine
    {
        $scale = (int) config('erp.precision.quantity_scale', 6);
        $required = $required->toScale($scale, RoundingMode::HalfUp);

        $available = $store === null
            ? BigDecimal::zero()
            : $this->balances->availableForProduction($item, [$store->id])->toScale($scale, RoundingMode::HalfUp);

        $shortage = $required->minus($available);
        $shortage = $shortage->isNegative() ? BigDecimal::zero() : $shortage;

        $afterRun = $available->minus($required);
        $afterRun = $afterRun->isNegative() ? BigDecimal::zero() : $afterRun;

        // Enough to make the batch and leave the store back at its reorder
        // level, so that the next plan does not start from a shortage.
        $restock = $shortage;

        if ($item->reorder_level !== null) {
            $toReorderLevel = BigDecimal::of($item->reorder_level)->minus($afterRun)->plus($shortage);
            $restock = $toReorderLevel->isGreaterThan($shortage) ? $toReorderLevel : $shortage;
        }

        return new RequirementLine(
            storeKind: $kind,
            itemId: $item->id,
            itemCode: $item->code,
            itemName: $item->name,
            uomId: $item->stock_uom_id,
            uomCode: $item->stockUom->code,
            percentage: $percentage,
            isQs: $isQs,
            asRequired: $asRequired,
            required: $required,
            available: $available,
            shortage: $shortage->toScale($scale, RoundingMode::HalfUp),
            restock: $restock->toScale($scale, RoundingMode::HalfUp),
            levelNow: $this->alerts->levelFor($item, $available),
            levelAfter: $shortage->isPositive() ? StockAlertLevel::OutOfStock : $this->alerts->levelFor($item, $afterRun),
            notes: $notes,
        );
    }
}
