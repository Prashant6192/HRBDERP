<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use App\Domain\MasterData\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product in a marketplace listing, and how many pieces of it go in a
 * single order. A plain listing has one; a combo has several.
 *
 * @property int $id
 * @property int $listing_id
 * @property int $item_id
 * @property int $units_per_order
 * @property int $line_no
 */
class MarketplaceListingComponent extends Model
{
    protected $fillable = ['listing_id', 'item_id', 'units_per_order', 'line_no'];

    protected function casts(): array
    {
        return ['units_per_order' => 'integer', 'line_no' => 'integer'];
    }

    /**
     * @return BelongsTo<MarketplaceListing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(MarketplaceListing::class, 'listing_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
