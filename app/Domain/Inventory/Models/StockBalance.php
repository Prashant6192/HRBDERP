<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\MasterData\Models\Item;
use App\Domain\Warehousing\Models\Warehouse;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The cached quantity of one item in one warehouse, per lot.
 *
 * Derived from the ledger. Never edited by hand; only the ledger and
 * reservation services write to it, and only while holding a row lock.
 *
 * @property int $item_id
 * @property int $warehouse_id
 * @property int|null $lot_id
 * @property string $on_hand
 * @property string $reserved
 */
class StockBalance extends Model
{
    protected $fillable = ['item_id', 'warehouse_id', 'lot_id', 'on_hand', 'reserved'];

    public function onHand(): BigDecimal
    {
        return BigDecimal::of($this->on_hand);
    }

    public function reserved(): BigDecimal
    {
        return BigDecimal::of($this->reserved);
    }

    /**
     * What is free to be reserved or issued from this row.
     */
    public function available(): BigDecimal
    {
        return $this->onHand()->minus($this->reserved());
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * @return BelongsTo<InventoryLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'lot_id');
    }
}
