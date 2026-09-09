<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

/**
 * Where a lot stands with quality control.
 *
 * Only an approved lot is available to production or dispatch. Everything
 * else is on the books — it is real stock and it is counted — but it cannot
 * be issued.
 */
enum LotQcStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case OnHold = 'on_hold';
    case NotRequired = 'not_required';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting QC',
            self::Approved => 'QC approved',
            self::Rejected => 'QC rejected',
            self::OnHold => 'On hold',
            self::NotRequired => 'No QC required',
        };
    }

    /**
     * Whether stock in this lot may be issued.
     */
    public function isReleasable(): bool
    {
        return in_array($this, [self::Approved, self::NotRequired], strict: true);
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved, self::NotRequired => 'success',
            self::Rejected => 'destructive',
            self::OnHold => 'muted',
        };
    }
}
