<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use App\Domain\MasterData\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product to take off the shelf for a parcel, in its stock unit. A
 * label line for a combo gives several; a "pack of 2" gives one of 2.
 *
 * @property int $id
 * @property int $shipment_id
 * @property int $shipment_line_id
 * @property int $item_id
 * @property string $units
 */
class ShipmentPick extends Model
{
    protected $fillable = ['shipment_id', 'shipment_line_id', 'item_id', 'units'];

    /**
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * @return BelongsTo<ShipmentLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(ShipmentLine::class, 'shipment_line_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
