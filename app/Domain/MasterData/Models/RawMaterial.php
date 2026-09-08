<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Models;

use App\Domain\MasterData\Concerns\ConstrainedToItemType;
use App\Domain\MasterData\Enums\ItemType;
use Database\Factories\RawMaterialFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A material that goes into a formulation.
 *
 * Backed by the shared items table and scoped to ItemType::RawMaterial.
 */
class RawMaterial extends Item
{
    use ConstrainedToItemType;

    public static function itemType(): ItemType
    {
        return ItemType::RawMaterial;
    }

    protected static function newFactory(): Factory
    {
        return RawMaterialFactory::new();
    }
}
