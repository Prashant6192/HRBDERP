<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\Item;
use Illuminate\Support\Carbon;

/**
 * Batch numbers: RM250909-001, PM250909-002, FG250909-001.
 *
 * Prefix by what the item is, date of receipt or manufacture, and a per-day
 * sequence per prefix. Readable on a sticker, sortable by date, and never
 * reused because the sequence is taken under lock.
 */
class BatchNumberGenerator
{
    public function __construct(private readonly SequenceService $sequences) {}

    public function generate(Item $item, ?Carbon $date = null): string
    {
        $date ??= now();
        $prefix = $this->prefixFor($item->type);
        $day = $date->format('ymd');

        $sequence = $this->sequences->next("batch:{$prefix}:{$day}");

        return sprintf('%s%s-%03d', $prefix, $day, $sequence);
    }

    public function prefixFor(ItemType $type): string
    {
        return match ($type) {
            ItemType::RawMaterial => 'RM',
            ItemType::PackagingMaterial => 'PM',
            ItemType::FinishedGood => 'FG',
            ItemType::SemiFinished => 'SF',
            ItemType::Consumable => 'CS',
        };
    }
}
