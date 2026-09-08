<?php

declare(strict_types=1);

namespace App\Http\Controllers\MasterData;

use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\PackagingMaterial;

class PackagingMaterialController extends ItemController
{
    protected function itemType(): ItemType
    {
        return ItemType::PackagingMaterial;
    }

    protected function modelClass(): string
    {
        return PackagingMaterial::class;
    }

    protected function routeName(): string
    {
        return 'packaging-materials';
    }

    protected function pageDirectory(): string
    {
        return 'packaging-materials';
    }

    protected function routeParameter(): string
    {
        return 'packaging_material';
    }
}
