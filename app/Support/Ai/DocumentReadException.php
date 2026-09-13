<?php

declare(strict_types=1);

namespace App\Support\Ai;

use RuntimeException;
use Throwable;

/**
 * Claude could not turn a document into data. `reason` says why, so the
 * caller can word it for the person on the screen.
 */
class DocumentReadException extends RuntimeException
{
    public const string NOT_CONFIGURED = 'not_configured';

    public const string API = 'api';

    public const string REFUSED = 'refused';

    public const string EMPTY = 'empty';

    public function __construct(public readonly string $reason, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
