<?php

declare(strict_types=1);

namespace App\Domain\Formulation\Exceptions;

use InvalidArgumentException;

/**
 * A formula operation that the record's current state does not allow.
 */
class FormulaStateException extends InvalidArgumentException {}
