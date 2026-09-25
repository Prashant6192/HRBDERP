<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use App\Domain\MasterData\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row of the label: the SKU text it printed and how many. Once matched
 * to a listing it has picks: the products and pieces to take off the
 * shelf. A plain listing's single product is also kept here, for display.
 *
 * @property int $id
 * @property int $shipment_id
 * @property int $line_no
 * @property string $seller_sku
 * @property string|null $description
 * @property int $quantity
 * @property int|null $listing_id
 * @property int|null $item_id
 * @property string|null $units
 */
class ShipmentLine extends Model
{
    protected $fillable = ['shipment_id', 'line_no', 'seller_sku', 'description', 'quantity', 'listing_id', 'item_id', 'units'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'line_no' => 'integer'];
    }

    /**
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * @return BelongsTo<MarketplaceListing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(MarketplaceListing::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return HasMany<ShipmentPick, $this>
     */
    public function picks(): HasMany
    {
        return $this->hasMany(ShipmentPick::class)->orderBy('id');
    }
}
