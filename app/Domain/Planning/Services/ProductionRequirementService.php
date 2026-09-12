<?php

declare(strict_types=1);

namespace App\Domain\Planning\Services;

use App\Domain\Formulation\DTOs\ScaledIngredient;
use App\Domain\Formulation\Models\FormulaVersion;
use App\Domain\Formulation\Services\FormulaScalingService;
use App\Domain\Inventory\Enums\StockAlertLevel;
use App\Domain\Inventory\Services\StockAlertService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\Inventory\Services\StockTransferService;
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
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Domain\Warehousing\Services\WarehouseResolver;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * "Can we make this batch?"
 *
 * Scales the recipe to the batch, expresses every material in its stock
 * unit, and sets each against what the raw material store at the chosen
 * manufacturing facility can release for production right now —
 * QC-approved, unexpired, unreserved. Stock at other facilities never
 * counts as available; it is reported separately so the planner can ask
 * for a transfer. The product's packaging list is worked out the same way
 * against that facility's packaging store. Nothing here writes;
 * ProductionPlanService keeps the answer.
 */
class ProductionRequirementService
{
    public function __construct(
        private readonly FormulaScalingService $scaling,
        private readonly StockBalanceService $balances,
        private readonly StockAlertService $alerts,
        private readonly UnitConversionService $conversions,
        private readonly WarehouseResolver $warehouses,
        private readonly StockTransferService $transfers,
    ) {}

    public function calculate(FormulaVersion $version, BigDecimal|string|int $quantity, Uom $uom, ?Product $product = null, ?Facility $facility = null): RequirementResult
    {
        $batch = BigDecimal::of($quantity);
        $result = new RequirementResult($batch, $uom->code, null);

        $facility ??= $this->warehouses->defaultManufacturingFacility();

        if ($facility === null) {
            $result->warnings[] = 'No facility has manufacturing enabled, so availability could not be checked. Enable manufacturing on a facility first.';
        }

        $rmStore = $facility === null ? null : $this->warehouses->findStoreOfType(StoreKind::RawMaterial->warehouseType(), $facility);

        if ($facility !== null && $rmStore === null) {
            $result->warnings[] = "{$facility->name} has no raw material store, so availability could not be checked. Add one on the facility screen.";
        }

        $scaled = $this->scaling->scale($version, $batch, $uom);

        foreach ($scaled->lines as $index => $line) {
            $result->rawMaterials[] = $this->rawMaterialLine($line, $rmStore, $facility);
        }

        if (! $scaled->isComplete()) {
            $result->warnings[] = "The recipe accounts for {$scaled->fixedPercentage}% of the batch and has no QS line; the remainder is not planned.";
        }

        $this->addPackaging($result, $product, $batch, $uom, $facility);

        return $result;
    }

    private function rawMaterialLine(ScaledIngredient $line, ?Warehouse $store, ?Facility $facility): RequirementLine
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

        return $this->line(StoreKind::RawMaterial, $item, $required, $store, $facility, $line->percentage, $line->isQs, $line->asRequired, $notes);
    }

    private function addPackaging(RequirementResult $result, ?Product $product, BigDecimal $batch, Uom $batchUom, ?Facility $facility): void
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

        $pmStore = $facility === null ? null : $this->warehouses->findStoreOfType(StoreKind::Packaging->warehouseType(), $facility);

        if ($facility !== null && $pmStore === null) {
            $result->warnings[] = "{$facility->name} has no packaging store, so packaging availability could not be checked.";
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

            $result->packaging[] = $this->line(StoreKind::Packaging, $material, $required, $pmStore, $facility, null, false, false, []);
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
    private function line(StoreKind $kind, Item $item, BigDecimal $required, ?Warehouse $store, ?Facility $facility, ?BigDecimal $percentage, bool $isQs, bool $asRequired, array $notes): RequirementLine
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
        $thresholds = $this->alerts->thresholdsFor($item, $store);

        if ($thresholds['reorder'] !== null) {
            $toReorderLevel = $thresholds['reorder']->minus($afterRun)->plus($shortage);
            $restock = $toReorderLevel->isGreaterThan($shortage) ? $toReorderLevel : $shortage;
        }

        // Short here, but held elsewhere? Say where, so the planner can raise
        // a transfer instead of a purchase.
        $elsewhere = [];

        if ($shortage->isPositive() && $facility !== null) {
            $elsewhere = $this->transfers->availableElsewhere($item, $facility, $kind->warehouseType())
                ->map(static fn (array $row): array => [
                    'facility_id' => $row['facility_id'],
                    'facility' => $row['facility'],
                    'quantity' => $row['quantity']->toScale($scale, RoundingMode::HalfUp)->__toString(),
                ])
                ->all();
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
            levelNow: $this->alerts->levelAt($item, $store, $available),
            levelAfter: $shortage->isPositive() ? StockAlertLevel::OutOfStock : $this->alerts->levelAt($item, $store, $afterRun),
            notes: $notes,
            availableElsewhere: $elsewhere,
        );
    }
}
