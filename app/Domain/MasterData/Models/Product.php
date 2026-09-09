<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Models;

use App\Domain\MasterData\Concerns\ConstrainedToItemType;
use App\Domain\MasterData\Enums\ItemType;
use App\Domain\Planning\Models\ProductPackagingLine;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A finished good that is sold.
 *
 * Backed by the shared items table and scoped to ItemType::FinishedGood.
 */
class Product extends Item
{
    use ConstrainedToItemType;

    public static function itemType(): ItemType
    {
        return ItemType::FinishedGood;
    }

    protected static function newFactory(): Factory
    {
        return ProductFactory::new();
    }

    /**
     * What each unit of this product is packed in.
     *
     * @return HasMany<ProductPackagingLine, $this>
     */
    public function packagingLines(): HasMany
    {
        return $this->hasMany(ProductPackagingLine::class, 'product_id')->orderBy('id');
    }
}
