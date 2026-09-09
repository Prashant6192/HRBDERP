<?php

declare(strict_types=1);

namespace App\Domain\Planning\Enums;

enum MaterialRequestStatus: string
{
    case Open = 'open';
    case PartiallyReceived = 'partially_received';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::PartiallyReceived => 'Partly received',
            self::Fulfilled => 'Fulfilled',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Open || $this === self::PartiallyReceived;
    }
}
