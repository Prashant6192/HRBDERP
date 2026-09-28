<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Stock went up in one or more stores: a receipt, a transfer landing, an
 * opening balance, a return, a count found more. Whoever was waiting on
 * that item there can try again — once the movement is committed.
 */
final class StockArrived implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  array<int, list<int>>  $items  item ids, keyed by warehouse id
     */
    public function __construct(public readonly array $items) {}
}
