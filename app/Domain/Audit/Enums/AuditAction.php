<?php

declare(strict_types=1);

namespace App\Domain\Audit\Enums;

/**
 * The vocabulary of audited actions.
 *
 * Model lifecycle events use Created/Updated/Deleted. Everything else is a
 * business event worth naming in its own right, because "the director viewed
 * a formula" and "the director updated a formula" are very different facts.
 */
enum AuditAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Restored = 'restored';

    case LoggedIn = 'logged_in';
    case LoggedOut = 'logged_out';
    case LoginFailed = 'login_failed';
    case PasswordChanged = 'password_changed';
    case PasswordReset = 'password_reset';

    case Activated = 'activated';
    case Deactivated = 'deactivated';
    case RolesChanged = 'roles_changed';
    case PermissionsChanged = 'permissions_changed';

    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Submitted = 'submitted';

    case FormulaViewed = 'formula.viewed';
    case FormulaUnlocked = 'formula.unlocked';
    case FormulaUnlockFailed = 'formula.unlock_failed';
    case FormulaPinChanged = 'formula.pin_changed';

    case StockReceived = 'stock.received';
    case StockAdjusted = 'stock.adjusted';
    case StockTransferred = 'stock.transferred';
    case StockReserved = 'stock.reserved';
    case StockConsumed = 'stock.consumed';

    case Exported = 'exported';
    case Imported = 'imported';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::Updated => 'Updated',
            self::Deleted => 'Deleted',
            self::Restored => 'Restored',
            self::LoggedIn => 'Logged in',
            self::LoggedOut => 'Logged out',
            self::LoginFailed => 'Failed login',
            self::PasswordChanged => 'Changed password',
            self::PasswordReset => 'Reset password',
            self::Activated => 'Activated',
            self::Deactivated => 'Deactivated',
            self::RolesChanged => 'Changed roles',
            self::PermissionsChanged => 'Changed permissions',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
            self::Submitted => 'Submitted for approval',
            self::FormulaViewed => 'Viewed formula',
            self::FormulaUnlocked => 'Unlocked formula access',
            self::FormulaUnlockFailed => 'Failed formula verification',
            self::FormulaPinChanged => 'Changed formula PIN',
            self::StockReceived => 'Received stock',
            self::StockAdjusted => 'Adjusted stock',
            self::StockTransferred => 'Transferred stock',
            self::StockReserved => 'Reserved stock',
            self::StockConsumed => 'Consumed stock',
            self::Exported => 'Exported',
            self::Imported => 'Imported',
        };
    }

    /**
     * Actions that deserve to stand out in the audit viewer.
     */
    public function isSensitive(): bool
    {
        return in_array($this, [
            self::FormulaViewed,
            self::FormulaUnlocked,
            self::FormulaUnlockFailed,
            self::FormulaPinChanged,
            self::RolesChanged,
            self::PermissionsChanged,
            self::Deleted,
            self::StockAdjusted,
        ], strict: true);
    }
}
