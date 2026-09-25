<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Exceptions;

use RuntimeException;

/**
 * Something about a label or a parcel that a person needs to put right.
 * The message is written for them.
 */
class OnlineOrderException extends RuntimeException {}
