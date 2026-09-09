<?php

declare(strict_types=1);

namespace App\Domain\Formulation\Enums;

/**
 * What the formula access trail records.
 *
 * Distinct from the general audit log: this is the security trail for the
 * company's trade secret, kept in its own table so it can be reviewed and
 * retained on its own terms.
 */
enum FormulaAccessAction: string
{
    case Unlocked = 'unlocked';
    case UnlockFailed = 'unlock_failed';
    case LockedOut = 'locked_out';
    case Locked = 'locked';
    case PinSet = 'pin_set';
    case Viewed = 'viewed';
    case Scaled = 'scaled';
    case Edited = 'edited';
    case Activated = 'activated';
    case Imported = 'imported';
    case Exported = 'exported';

    public function label(): string
    {
        return match ($this) {
            self::Unlocked => 'Unlocked formulations',
            self::UnlockFailed => 'Failed unlock attempt',
            self::LockedOut => 'Locked out after repeated failures',
            self::Locked => 'Locked formulations',
            self::PinSet => 'Set formula PIN',
            self::Viewed => 'Viewed a formula',
            self::Scaled => 'Scaled a formula to a batch',
            self::Edited => 'Edited a formula',
            self::Activated => 'Activated a formula version',
            self::Imported => 'Imported formulations',
            self::Exported => 'Exported a formula',
        };
    }
}
