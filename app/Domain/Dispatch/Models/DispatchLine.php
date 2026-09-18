<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\Models;

use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\MasterData\Models\Item;
use App\Domain\Measurement\Models\Uom;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One batch of one product on a consignment, priced and taxed as the
 * invoice carries it.
 *
 * @property int $id
 * @property int $dispatch_id
 * @property int $line_no
 * @property int $item_id
 * @property int $lot_id
 * @property int $uom_id
 * @property string $quantity
 * @property string $unit_price
 * @property string $discount_percent
 * @property string|null $hsn_code
 * @property string $gst_rate
 * @property string $taxable_value
 * @property string $cgst
 * @property string $sgst
 * @property string $igst
 * @property string $line_total
 */
class DispatchLine extends Model
{
    protected $fillable = [
        'dispatch_id', 'line_no', 'item_id', 'lot_id', 'uom_id', 'quantity', 'unit_price', 'discount_percent',
        'hsn_code', 'gst_rate', 'taxable_value', 'cgst', 'sgst', 'igst', 'line_total', 'description',
    ];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Dispatch, $this>
     */
    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
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
        return $this->belongsTo(Uom::class);
    }
}
