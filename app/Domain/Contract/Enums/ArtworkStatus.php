<?php

declare(strict_types=1);

namespace App\Domain\Contract\Enums;

enum ArtworkStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Superseded = 'superseded';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting approval',
            self::Approved => 'Approved',
            self::Superseded => 'Superseded',
            self::Rejected => 'Rejected',
        };
    }
}
