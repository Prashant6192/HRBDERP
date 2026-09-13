<?php

declare(strict_types=1);

namespace App\Domain\Documents\Enums;

enum DocumentStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Superseded = 'superseded';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved · current',
            self::Superseded => 'Superseded',
            self::Withdrawn => 'Withdrawn',
        };
    }
}
