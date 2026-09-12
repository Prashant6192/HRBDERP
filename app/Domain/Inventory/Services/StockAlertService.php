<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Enums\StockAlertLevel;
use App\Domain\MasterData\Models\Item;
use App\Domain\Warehousing\Models\StoreItemLevel;
use App\Domain\Warehousing\Models\Warehouse;
use Brick\Math\BigDecimal;

/**
 * Turns a quantity into an alert level, using the item's own thresholds.
 *
 *   on hand <= 0                          → Out of stock
 *   on hand <= minimum_stock              → Critical
 *   on hand <= reorder_level              → Low
 *   on hand <= reorder_level x multiplier → Moderate
 *   otherwise                             → Healthy
 *
 * The multiplier lives in config so the width of the "moderate" band can be
 * tuned without touching every item. An item with no thresholds set can only
 * ever be Healthy or Out of stock — the system does not guess.
 *
 * A store may carry its own thresholds for an item (StoreItemLevel); when
 * asked about a particular store those win over the item's company-wide
 * figures.
 */
class StockAlertService
{
    public function levelFor(Item $item, BigDecimal|string|int $onHand): StockAlertLevel
    {
        return $this->levelAt($item, null, $onHand);
    }

    public function levelAt(Item $item, ?Warehouse $store, BigDecimal|string|int $onHand): StockAlertLevel
    {
        $quantity = BigDecimal::of($onHand);
        $thresholds = $this->thresholdsFor($item, $store);

        if ($quantity->isLessThanOrEqualTo(0)) {
            return StockAlertLevel::OutOfStock;
        }

        if ($thresholds['minimum'] !== null && $quantity->isLessThanOrEqualTo($thresholds['minimum'])) {
            return StockAlertLevel::Critical;
        }

        if ($thresholds['reorder'] !== null) {
            $reorder = $thresholds['reorder'];

            if ($quantity->isLessThanOrEqualTo($reorder)) {
                return StockAlertLevel::Low;
            }

            $moderateCeiling = $reorder->multipliedBy($thresholds['multiplier'] ?? $this->moderateMultiplier());

            if ($quantity->isLessThanOrEqualTo($moderateCeiling)) {
                return StockAlertLevel::Moderate;
            }
        }

        return StockAlertLevel::Healthy;
    }

    public function moderateMultiplier(): BigDecimal
    {
        return BigDecimal::of((string) config('erp.stock_alerts.moderate_multiplier', '2'));
    }

    /**
     * The thresholds that apply to an item in a store: the store's own where
     * set, the item's otherwise.
     *
     * @return array{minimum: BigDecimal|null, reorder: BigDecimal|null, multiplier: BigDecimal|null, store_specific: bool}
     */
    public function thresholdsFor(Item $item, ?Warehouse $store): array
    {
        $minimum = $item->minimum_stock !== null ? BigDecimal::of($item->minimum_stock) : null;
        $reorder = $item->reorder_level !== null ? BigDecimal::of($item->reorder_level) : null;
        $multiplier = null;
        $specific = false;

        if ($store !== null) {
            $override = $store->relationLoaded('itemLevels')
                ? $store->itemLevels->firstWhere('item_id', $item->id)
                : StoreItemLevel::query()->where('warehouse_id', $store->id)->where('item_id', $item->id)->first();

            if ($override !== null) {
                $specific = true;
                $minimum = $override->minimum_stock !== null ? BigDecimal::of($override->minimum_stock) : $minimum;
                $reorder = $override->reorder_level !== null ? BigDecimal::of($override->reorder_level) : $reorder;
                $multiplier = $override->moderate_multiplier !== null ? BigDecimal::of($override->moderate_multiplier) : null;
            }
        }

        return ['minimum' => $minimum, 'reorder' => $reorder, 'multiplier' => $multiplier, 'store_specific' => $specific];
    }
}
