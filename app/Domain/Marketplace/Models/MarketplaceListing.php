<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\MasterData\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * What a marketplace prints for a product ("Medicated oil 300 ml",
 * "Medicated_Oil_500ml_Po2"), and which product that is. Mapped once, the
 * next label with the same text is matched without asking.
 *
 * @property int $id
 * @property int $marketplace_id
 * @property int $brand_id
 * @property string $seller_sku
 * @property string $sku_key
 * @property int $item_id
 * @property int $units_per_order
 * @property bool $is_active
 */
class MarketplaceListing extends Model
{
    use RecordsAuditTrail;

    protected $fillable = [
        'marketplace_id', 'brand_id', 'seller_sku', 'sku_key', 'item_id', 'units_per_order', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'units_per_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function auditLabel(): string
    {
        return $this->seller_sku;
    }

    /**
     * The same SKU printed with different spacing or case is the same SKU.
     */
    public static function keyFor(string $sku): string
    {
        return Str::of($sku)->lower()->squish()->replaceMatches('/\s*([|_\-\/])\s*/', '$1')->toString();
    }

    /**
     * @return BelongsTo<Marketplace, $this>
     */
    public function marketplace(): BelongsTo
    {
        return $this->belongsTo(Marketplace::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
