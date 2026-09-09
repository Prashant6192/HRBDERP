<?php

declare(strict_types=1);

namespace App\Domain\Planning\Models;

use App\Domain\MasterData\Models\Item;
use App\Domain\MasterData\Models\PackagingMaterial;
use App\Domain\MasterData\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One component of a product's pack: a bottle, a cap, a label, a share of
 * a shipping carton — and how many of it each unit sold takes.
 *
 * @property int $id
 * @property int $product_id
 * @property int $packaging_material_id
 * @property string $quantity_per_unit
 * @property Item $packagingMaterial
 */
class ProductPackagingLine extends Model
{
    protected $fillable = ['product_id', 'packaging_material_id', 'quantity_per_unit', 'notes'];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * @return BelongsTo<PackagingMaterial, $this>
     */
    public function packagingMaterial(): BelongsTo
    {
        return $this->belongsTo(PackagingMaterial::class, 'packaging_material_id');
    }
}
