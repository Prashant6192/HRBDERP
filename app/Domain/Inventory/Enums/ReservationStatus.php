<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

enum ReservationStatus: string
{
    case Active = 'active';
    case Consumed = 'consumed';
    case Released = 'released';

    public function isOpen(): bool
    {
        return $this === self::Active;
    }
}
