<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Enums\StockAlertLevel;
use App\Domain\MasterData\Models\Item;
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
 */
class StockAlertService
{
    public function levelFor(Item $item, BigDecimal|string|int $onHand): StockAlertLevel
    {
        $quantity = BigDecimal::of($onHand);

        if ($quantity->isLessThanOrEqualTo(0)) {
            return StockAlertLevel::OutOfStock;
        }

        if ($item->minimum_stock !== null && $quantity->isLessThanOrEqualTo(BigDecimal::of($item->minimum_stock))) {
            return StockAlertLevel::Critical;
        }

        if ($item->reorder_level !== null) {
            $reorder = BigDecimal::of($item->reorder_level);

            if ($quantity->isLessThanOrEqualTo($reorder)) {
                return StockAlertLevel::Low;
            }

            $moderateCeiling = $reorder->multipliedBy($this->moderateMultiplier());

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
}
