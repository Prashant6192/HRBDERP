<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Enums;

/**
 * A claim on the marketplace for goods that came back damaged or did not
 * come back at all.
 *
 *   none  — nothing to claim: everything came back sellable
 *   open  — owed; to be raised on the marketplace before the deadline
 *   won   — the marketplace paid
 *   lost  — refused, or the deadline passed
 */
enum ClaimStatus: string
{
    case None = 'none';
    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No claim',
            self::Open => 'Claim to raise',
            self::Won => 'Claim paid',
            self::Lost => 'Claim lost',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::None => 'neutral',
            self::Open => 'warning',
            self::Won => 'success',
            self::Lost => 'danger',
        };
    }
}
