<?php

declare(strict_types=1);

namespace App\Domain\Formulation\Exceptions;

use RuntimeException;

/**
 * A failed second-factor check on the formulation module.
 *
 * Carries nothing that would help an attacker: the message is the same for a
 * wrong PIN and a missing one, and the lockout variant only says how long.
 */
class FormulaAccessException extends RuntimeException
{
    public static function invalidPin(): self
    {
        return new self('That PIN was not accepted.');
    }

    public static function lockedOut(int $minutes): self
    {
        return new self("Too many failed attempts. Formulations are locked for {$minutes} more ".($minutes === 1 ? 'minute' : 'minutes').'.');
    }

    public static function noPin(): self
    {
        return new self('Set a formula PIN before unlocking formulations.');
    }
}
