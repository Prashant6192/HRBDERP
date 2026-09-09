<?php

declare(strict_types=1);

namespace App\Http\Controllers\MasterData;

use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\Item;
use App\Domain\MasterData\Models\PackagingMaterial;
use App\Domain\MasterData\Models\Product;
use App\Domain\Planning\Models\ProductPackagingLine;

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

    /**
     * The packaging list, and the materials it may be built from.
     *
     * @return array<string, mixed>
     */
    protected function extraShowProps(Item $item): array
    {
        $lines = ProductPackagingLine::query()
            ->where('product_id', $item->id)
            ->with(['packagingMaterial:id,code,name,stock_uom_id', 'packagingMaterial.stockUom:id,code'])
            ->orderBy('id')
            ->get()
            ->map(static fn (ProductPackagingLine $line): array => [
                'id' => $line->id,
                'packaging_material_id' => $line->packaging_material_id,
                'code' => $line->packagingMaterial->code,
                'name' => $line->packagingMaterial->name,
                'uom' => $line->packagingMaterial->stockUom?->code,
                'quantity_per_unit' => $line->quantity_per_unit,
                'notes' => $line->notes,
            ])
            ->all();

        return [
            'packagingLines' => $lines,
            'packagingOptions' => PackagingMaterial::query()->active()->orderBy('name')->get(['id', 'code', 'name'])
                ->map(static fn (PackagingMaterial $p): array => ['value' => $p->id, 'label' => "{$p->name} ({$p->code})"])
                ->all(),
        ];
    }
}
