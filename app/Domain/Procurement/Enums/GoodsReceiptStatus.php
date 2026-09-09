<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Enums;

enum GoodsReceiptStatus: string
{
    case Draft = 'draft';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Received => 'Received',
            self::Cancelled => 'Cancelled',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Draft => 'muted',
            self::Received => 'success',
            self::Cancelled => 'destructive',
        };
    }

    /**
     * Lines may only be changed before the stock exists.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
