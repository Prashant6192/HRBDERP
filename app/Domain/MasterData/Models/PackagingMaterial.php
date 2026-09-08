<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Models;

use App\Domain\MasterData\Concerns\ConstrainedToItemType;
use App\Domain\MasterData\Enums\ItemType;
use Database\Factories\PackagingMaterialFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A bottle, cap, carton, label or other packaging component.
 *
 * Backed by the shared items table and scoped to ItemType::PackagingMaterial.
 */
class PackagingMaterial extends Item
{
    use ConstrainedToItemType;

    public static function itemType(): ItemType
    {
        return ItemType::PackagingMaterial;
    }

    protected static function newFactory(): Factory
    {
        return PackagingMaterialFactory::new();
    }
}
