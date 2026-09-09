<?php

declare(strict_types=1);

namespace App\Domain\Manufacturing\Enums;

enum ManufacturingOrderStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved · materials reserved',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::Approved, self::InProgress], strict: true);
    }
}
