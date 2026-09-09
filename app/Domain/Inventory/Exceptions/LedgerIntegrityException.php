<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

/**
 * A posting that the ledger refuses on principle: a zero line, a receipt
 * with a negative quantity, a transfer that does not balance.
 */
final class LedgerIntegrityException extends RuntimeException {}
