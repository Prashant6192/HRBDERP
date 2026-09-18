<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\Exceptions;

use RuntimeException;

/**
 * Something about a consignment that a person has to put right: the wrong
 * store, a batch that is not released, a customer who may not have it, an
 * invoice not yet recorded.
 */
class DispatchException extends RuntimeException {}
