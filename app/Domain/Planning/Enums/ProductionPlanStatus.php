<?php

declare(strict_types=1);

namespace App\Domain\Planning\Enums;

enum ProductionPlanStatus: string
{
    case Draft = 'draft';
    case Checked = 'checked';
    case Requested = 'requested';
    case InProduction = 'in_production';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Checked => 'Checked',
            self::Requested => 'Requests raised',
            self::InProduction => 'In production',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::Checked, self::Requested], strict: true);
    }

    public function canBeChecked(): bool
    {
        return in_array($this, [self::Draft, self::Checked, self::Requested], strict: true);
    }
}
