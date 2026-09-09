<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTOs;

use App\Domain\Inventory\Enums\InventoryTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A complete movement, ready to post.
 *
 * @param  list<LedgerLine>  $lines
 */
final readonly class LedgerPosting
{
    public function __construct(
        public InventoryTransactionType $type,
        public int $warehouseId,
        public array $lines,
        public ?int $counterpartWarehouseId = null,
        public ?Model $reference = null,
        public ?string $reason = null,
        public ?Carbon $transactedAt = null,
        public ?int $createdBy = null,
    ) {}
}
