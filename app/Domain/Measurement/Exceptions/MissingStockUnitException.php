<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Exceptions;

use RuntimeException;

/**
 * A material on a recipe or a pack list has no unit its stock is held in,
 * so there is no way to say how much of it a batch needs.
 *
 * A RuntimeException, so that the screens which already report planning
 * problems in plain words report this one the same way.
 */
class MissingStockUnitException extends RuntimeException {}
