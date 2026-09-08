<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Enums;

enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Returned => 'Returned for changes',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Returned], strict: true);
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected, self::Cancelled => 'destructive',
            self::Returned => 'muted',
        };
    }
}
