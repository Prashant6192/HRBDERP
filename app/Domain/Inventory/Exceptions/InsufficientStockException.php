<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use App\Domain\MasterData\Models\Item;
use App\Domain\Warehousing\Models\Warehouse;
use Brick\Math\BigDecimal;
use RuntimeException;

/**
 * Thrown, and the surrounding database transaction rolled back, when a
 * movement would take stock below zero or below what is already reserved.
 */
final class InsufficientStockException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?BigDecimal $shortage = null,
    ) {
        parent::__construct($message);
    }

    public static function forIssue(Item $item, Warehouse $warehouse, BigDecimal $requested, BigDecimal $available): self
    {
        return new self(sprintf(
            'Cannot issue %s %s of %s from %s: only %s available.',
            $requested->strippedOfTrailingZeros(),
            $item->stockUom?->code ?? '',
            $item->code,
            $warehouse->code,
            $available->strippedOfTrailingZeros(),
        ), $requested->minus($available));
    }

    public static function forReservation(Item $item, Warehouse $warehouse, BigDecimal $requested, BigDecimal $available): self
    {
        return new self(sprintf(
            'Cannot reserve %s %s of %s in %s: only %s is free to reserve.',
            $requested->strippedOfTrailingZeros(),
            $item->stockUom?->code ?? '',
            $item->code,
            $warehouse->code,
            $available->strippedOfTrailingZeros(),
        ), $requested->minus($available));
    }

    public static function reservedStock(Item $item, Warehouse $warehouse, BigDecimal $requested, BigDecimal $unreserved): self
    {
        return new self(sprintf(
            'Cannot issue %s of %s from %s: %s is reserved for production and only %s is free.',
            $requested->strippedOfTrailingZeros(),
            $item->code,
            $warehouse->code,
            $requested->minus($unreserved)->strippedOfTrailingZeros(),
            $unreserved->strippedOfTrailingZeros(),
        ), $requested->minus($unreserved));
    }
}
