<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use App\Domain\MasterData\Models\Item;
use App\Domain\Warehousing\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product of a returned parcel: how many left, and how many came back
 * sellable, damaged or not at all. Always adds up to what left.
 *
 * @property int $id
 * @property int $shipment_return_id
 * @property int $item_id
 * @property string $sent
 * @property string $good
 * @property string $damaged
 * @property string $missing
 * @property int|null $good_warehouse_id
 * @property int|null $damaged_warehouse_id
 */
class ShipmentReturnLine extends Model
{
    protected $fillable = ['shipment_return_id', 'item_id', 'sent', 'good', 'damaged', 'missing', 'good_warehouse_id', 'damaged_warehouse_id'];

    /**
     * @return BelongsTo<ShipmentReturn, $this>
     */
    public function shipmentReturn(): BelongsTo
    {
        return $this->belongsTo(ShipmentReturn::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function goodStore(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'good_warehouse_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function damagedStore(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'damaged_warehouse_id');
    }
}
