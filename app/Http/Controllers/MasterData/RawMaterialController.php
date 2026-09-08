<?php

declare(strict_types=1);

namespace App\Http\Controllers\MasterData;

use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\RawMaterial;

class RawMaterialController extends ItemController
{
    protected function itemType(): ItemType
    {
        return ItemType::RawMaterial;
    }

    protected function modelClass(): string
    {
        return RawMaterial::class;
    }

    protected function routeName(): string
    {
        return 'raw-materials';
    }

    protected function pageDirectory(): string
    {
        return 'raw-materials';
    }

    protected function routeParameter(): string
    {
        return 'raw_material';
    }
}
