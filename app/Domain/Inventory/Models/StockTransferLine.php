<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\MasterData\Models\Item;
use App\Domain\Measurement\Models\Uom;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item (and, once picked, one lot) on a stock transfer.
 *
 * Quantities are in the item's stock unit.
 *
 * @property int $stock_transfer_id
 * @property int $line_no
 * @property int $item_id
 * @property int|null $lot_id
 * @property int $uom_id
 * @property string $quantity_requested
 * @property string $quantity_dispatched
 * @property string $quantity_received
 * @property string $quantity_written_off
 */
class StockTransferLine extends Model
{
    protected $fillable = [
        'stock_transfer_id', 'line_no', 'item_id', 'lot_id', 'uom_id',
        'quantity_requested', 'quantity_dispatched', 'quantity_received', 'quantity_written_off', 'discrepancy_notes',
    ];

    public function requested(): BigDecimal
    {
        return BigDecimal::of($this->quantity_requested);
    }

    public function dispatched(): BigDecimal
    {
        return BigDecimal::of($this->quantity_dispatched ?? '0');
    }

    public function received(): BigDecimal
    {
        return BigDecimal::of($this->quantity_received ?? '0');
    }

    public function writtenOff(): BigDecimal
    {
        return BigDecimal::of($this->quantity_written_off ?? '0');
    }

    /**
     * Dispatched but neither received nor written off: still on the road.
     */
    public function outstanding(): BigDecimal
    {
        return $this->dispatched()->minus($this->received())->minus($this->writtenOff());
    }

    /**
     * @return BelongsTo<StockTransfer, $this>
     */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    /**
     * @return BelongsTo<InventoryLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'lot_id');
    }

    /**
     * @return BelongsTo<Uom, $this>
     */
    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'uom_id');
    }
}
