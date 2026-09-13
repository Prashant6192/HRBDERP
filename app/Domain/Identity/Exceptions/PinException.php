<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use RuntimeException;

class PinException extends RuntimeException
{
    public static function noPin(): self
    {
        return new self('You have not set a personal PIN yet. Set one under Settings → Security, then sign the decision.');
    }

    public static function invalid(int $attemptsLeft): self
    {
        return new self($attemptsLeft > 0
            ? "That PIN is not right. {$attemptsLeft} attempt".($attemptsLeft === 1 ? '' : 's').' left.'
            : 'That PIN is not right.');
    }

    public static function lockedOut(int $minutes): self
    {
        return new self("Too many wrong PINs. Try again in {$minutes} minute".($minutes === 1 ? '' : 's').'.');
    }
}
