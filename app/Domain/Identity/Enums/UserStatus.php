<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * Whether an employee account may be used.
 *
 * Accounts are deactivated rather than deleted: an employee who has left still
 * has their name against two years of stock movements and approvals, and those
 * records have to keep pointing somewhere.
 */
enum UserStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Suspended => 'Suspended',
        };
    }

    /**
     * Whether an account in this state may authenticate.
     */
    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Inactive => 'muted',
            self::Suspended => 'destructive',
        };
    }
}
