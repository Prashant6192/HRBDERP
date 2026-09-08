<?php

declare(strict_types=1);

namespace App\Http\Controllers\MasterData;

use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\Product;

class ProductController extends ItemController
{
    protected function itemType(): ItemType
    {
        return ItemType::FinishedGood;
    }

    protected function modelClass(): string
    {
        return Product::class;
    }

    protected function routeName(): string
    {
        return 'products';
    }

    protected function pageDirectory(): string
    {
        return 'products';
    }

    protected function routeParameter(): string
    {
        return 'product';
    }
}
