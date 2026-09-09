<?php

declare(strict_types=1);

namespace App\Domain\Formulation\Enums;

enum FormulaVersionStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Superseded = 'superseded';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Superseded => 'Superseded',
            self::Rejected => 'Rejected',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
