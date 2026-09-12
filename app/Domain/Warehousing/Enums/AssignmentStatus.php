<?php

declare(strict_types=1);

namespace App\Domain\Warehousing\Enums;

enum AssignmentStatus: string
{
    case Active = 'active';
    case Ended = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Ended => 'Ended',
        };
    }
}
