<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use App\Domain\MasterData\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product in a parcel: the SKU text the label printed, how many, and
 * — once mapped — which product and how many of its stock units that is.
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
}
