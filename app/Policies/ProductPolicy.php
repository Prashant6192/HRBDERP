<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\MasterData\Enums\ItemType;

class ProductPolicy extends ItemPolicy
{
    protected function itemType(): ItemType
    {
        return ItemType::FinishedGood;
    }
}
