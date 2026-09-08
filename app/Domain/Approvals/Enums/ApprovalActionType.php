<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Enums;

enum ApprovalActionType: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Returned = 'returned';
    case Cancelled = 'cancelled';
    case Commented = 'commented';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Returned => 'Returned',
            self::Cancelled => 'Cancelled',
            self::Commented => 'Commented',
        };
    }
}
