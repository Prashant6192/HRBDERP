<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Services;

use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\Item;

/**
 * The next free item code for a type: RM-1001, RM-1002, PM-1001 …
 *
 * Follows on from the highest code already in use (deleted ones included,
 * so a code is never reissued). The unique index on items.code is the real
 * guarantee; this only makes the suggestion.
 */
class ItemCodeGenerator
{
    private const int FIRST = 1001;

    public function next(ItemType $type): string
    {
        $prefix = $this->prefixFor($type);

        $highest = Item::withTrashed()
            ->where('code', '~', '^'.$prefix.'-[0-9]+$')
            ->selectRaw("max(cast(substring(code from '[0-9]+$') as integer)) as highest")
            ->value('highest');

        $next = max(self::FIRST, ((int) $highest) + 1);

        return sprintf('%s-%04d', $prefix, $next);
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
