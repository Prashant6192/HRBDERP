<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Formulation\Services\FormulaSecurityService;
use App\Domain\Identity\Exceptions\PinException;
use App\Models\User;

/**
 * One personal PIN per person, used wherever a decision must be signed
 * rather than merely clicked: unlocking formulations and passing or
 * failing a batch at QC. Hashed at rest; wrong guesses are counted and a
 * run of them locks the PIN for a while.
 */
class PersonalPinService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly FormulaSecurityService $formulas,
    ) {}

    public function required(string $purpose): bool
    {
        return match ($purpose) {
            'qc' => (bool) config('erp.qc.require_pin', true),
            default => true,
        };
    }

    /**
     * @throws PinException
     */
    public function verify(User $user, ?string $pin, string $purpose = 'qc'): void
    {
        $user->refresh();

        if ($user->formula_pin_locked_until !== null && $user->formula_pin_locked_until->isFuture()) {
            throw PinException::lockedOut(max(1, (int) ceil(now()->diffInSeconds($user->formula_pin_locked_until, false) / 60)));
        }

        if (! $user->hasFormulaPin()) {
            throw PinException::noPin();
        }

        if ($pin === null || $pin === '' || ! $user->verifyFormulaPin($pin)) {
            $max = (int) config('erp.formula_security.max_attempts', 5);
            $attempts = (int) $user->formula_pin_failed_attempts + 1;
            $lockout = $attempts >= $max;

            $user->forceFill([
                'formula_pin_failed_attempts' => $lockout ? 0 : $attempts,
                'formula_pin_locked_until' => $lockout ? now()->addMinutes((int) config('erp.formula_security.lockout_minutes', 15)) : null,
            ])->saveQuietly();

            $this->audit->log(AuditAction::LoginFailed, $user, description: "Wrong personal PIN ({$purpose})", context: ['purpose' => $purpose, 'locked_out' => $lockout], actor: $user);

            if ($lockout) {
                throw PinException::lockedOut((int) config('erp.formula_security.lockout_minutes', 15));
            }

            throw PinException::invalid($max - $attempts);
        }

        if ((int) $user->formula_pin_failed_attempts > 0) {
            $user->forceFill(['formula_pin_failed_attempts' => 0, 'formula_pin_locked_until' => null])->saveQuietly();
        }
    }

    /**
     * Set or change the PIN. Any formula unlock the old PIN opened ends.
     */
    public function set(User $user, string $pin): void
    {
        $user->setFormulaPin($pin);
        $this->formulas->revokeAllFor($user);
        $this->audit->log(AuditAction::FormulaPinChanged, $user, description: 'Personal PIN set', actor: $user);
    }
}
