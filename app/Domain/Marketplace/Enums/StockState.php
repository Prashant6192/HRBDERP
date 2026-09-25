<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Enums;

/**
 * Where a parcel's goods stand in the store.
 *
 *   unmapped  — the label names something no product is mapped to yet
 *   short     — mapped, but the store does not have enough free
 *   reserved  — held on the shelf for this parcel
 *   consumed  — packed: the stock has left through the ledger
 *   released  — cancelled: what was held is free again
 *   returned  — came back from the customer or the courier
 */
enum StockState: string
{
    case Unmapped = 'unmapped';
    case Short = 'short';
    case Reserved = 'reserved';
    case Consumed = 'consumed';
    case Released = 'released';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Unmapped => 'Product not mapped',
            self::Short => 'Short in store',
            self::Reserved => 'Stock held',
            self::Consumed => 'Stock out',
            self::Released => 'Released',
            self::Returned => 'Back in store',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Unmapped, self::Short => 'danger',
            self::Reserved => 'info',
            self::Consumed => 'success',
            self::Released, self::Returned => 'neutral',
        };
    }
}
