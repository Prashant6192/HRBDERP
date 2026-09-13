<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

enum StockCountStatus: string
{
    case Counting = 'counting';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Counting => 'Counting',
            self::Submitted => 'Submitted · awaiting approval',
            self::Approved => 'Approved · adjustments posted',
            self::Cancelled => 'Cancelled',
        };
    }
}
