<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTOs;

use App\Domain\Inventory\Enums\InventoryTransactionType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

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
        public ?CarbonInterface $transactedAt = null,
        public ?int $createdBy = null,
    ) {}
}
