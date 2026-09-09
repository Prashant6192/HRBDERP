<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTOs;

use Brick\Math\BigDecimal;

/**
 * One line of a posting, before it is written.
 *
 * Quantity is signed and in the item's stock unit. Callers that hold a
 * quantity in any other unit convert it with UnitConversionService first;
 * the ledger does not convert.
 */
final readonly class LedgerLine
{
    public BigDecimal $quantity;

    public function __construct(
        public int $itemId,
        public int $warehouseId,
        BigDecimal|string|int $quantity,
        public ?int $lotId = null,
        public ?int $locationId = null,
        public BigDecimal|string|null $unitCost = null,
    ) {
        $this->quantity = BigDecimal::of($quantity);
    }
}
