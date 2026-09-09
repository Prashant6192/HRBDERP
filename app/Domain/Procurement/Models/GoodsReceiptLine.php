<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Models;

use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\MasterData\Models\Item;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Quality\Models\QcInspection;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $goods_receipt_id
 * @property int $item_id
 * @property string $quantity
 * @property int $uom_id
 * @property string $stock_quantity
 * @property string|null $unit_price
 * @property string|null $batch_number
 * @property int|null $lot_id
 * @property int|null $qc_inspection_id
 */
class GoodsReceiptLine extends Model
{
    protected $fillable = [
        'goods_receipt_id', 'item_id', 'quantity', 'uom_id', 'stock_quantity',
        'unit_price', 'supplier_batch_ref', 'manufactured_at', 'expiry_at',
        'batch_number', 'lot_id', 'qc_inspection_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'manufactured_at' => 'date',
            'expiry_at' => 'date',
        ];
    }

    public function stockQuantity(): BigDecimal
    {
        return BigDecimal::of($this->stock_quantity);
    }

    /**
     * Cost per stock unit, derived from the price per entered unit.
     */
    public function unitCostPerStockUnit(): ?BigDecimal
    {
        if ($this->unit_price === null) {
            return null;
        }

        return BigDecimal::of($this->unit_price)
            ->multipliedBy(BigDecimal::of($this->quantity))
            ->dividedBy($this->stockQuantity(), 4, RoundingMode::HalfUp);
    }

    /**
     * @return BelongsTo<GoodsReceipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    /**
     * @return BelongsTo<Uom, $this>
     */
    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'uom_id');
    }

    /**
     * @return BelongsTo<InventoryLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'lot_id');
    }

    /**
     * @return BelongsTo<QcInspection, $this>
     */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(QcInspection::class, 'qc_inspection_id');
    }
}
