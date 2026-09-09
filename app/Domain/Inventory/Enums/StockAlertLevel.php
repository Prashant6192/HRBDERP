<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

/**
 * How worried to be about an item's stock.
 *
 * Ordered from calm to alarmed, so that the dashboard can sort by severity
 * and a store manager sees the drum that is about to run out at the top.
 */
enum StockAlertLevel: string
{
    case Healthy = 'healthy';
    case Moderate = 'moderate';
    case Low = 'low';
    case Critical = 'critical';
    case OutOfStock = 'out_of_stock';

    public function label(): string
    {
        return match ($this) {
            self::Healthy => 'Healthy',
            self::Moderate => 'Moderate quantity available',
            self::Low => 'Low quantity available',
            self::Critical => 'Critically low',
            self::OutOfStock => 'Out of stock',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Healthy => 'Healthy',
            self::Moderate => 'Moderate',
            self::Low => 'Low',
            self::Critical => 'Critical',
            self::OutOfStock => 'Out',
        };
    }

    /**
     * 0 is calm; higher is worse. Used for sorting.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Healthy => 0,
            self::Moderate => 1,
            self::Low => 2,
            self::Critical => 3,
            self::OutOfStock => 4,
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Healthy => 'success',
            self::Moderate => 'info',
            self::Low => 'warning',
            self::Critical, self::OutOfStock => 'destructive',
        };
    }

    public function needsAttention(): bool
    {
        return $this->severity() >= self::Moderate->severity();
    }
}
