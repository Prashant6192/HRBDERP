<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Assistant;

use RuntimeException;
use Throwable;

class AssistantException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
