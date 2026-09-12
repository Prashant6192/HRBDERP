<?php

declare(strict_types=1);

namespace App\Domain\Warehousing\Exceptions;

use RuntimeException;

/**
 * A facility or store change the business rules refuse — the message is
 * written for the person on the screen.
 */
class FacilityException extends RuntimeException {}
