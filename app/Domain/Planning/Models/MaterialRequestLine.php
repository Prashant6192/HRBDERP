<?php

declare(strict_types=1);

namespace App\Domain\Planning\Models;

use App\Domain\Inventory\Enums\StockAlertLevel;
use App\Domain\MasterData\Models\Item;
use App\Domain\Measurement\Models\Uom;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $material_request_id
 * @property int $line_no
 * @property int $item_id
 * @property int $uom_id
 * @property string $required_quantity
 * @property string $available_quantity
 * @property string $quantity_to_order
 * @property string $restock_quantity
 * @property string $received_quantity
 * @property StockAlertLevel $alert_level
 * @property Item $item
 * @property Uom $uom
 */
class MaterialRequestLine extends Model
{
    protected $fillable = [
        'material_request_id', 'line_no', 'item_id', 'uom_id', 'required_quantity', 'available_quantity',
        'quantity_to_order', 'restock_quantity', 'received_quantity', 'alert_level',
    ];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'alert_level' => StockAlertLevel::class,
        ];
    }

    /**
     * @return BelongsTo<MaterialRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(MaterialRequest::class, 'material_request_id');
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

    public function outstanding(): BigDecimal
    {
        $outstanding = BigDecimal::of($this->quantity_to_order)->minus($this->received_quantity);

        return $outstanding->isNegative() ? BigDecimal::zero() : $outstanding;
    }

    public function isCovered(): bool
    {
        return BigDecimal::of($this->received_quantity)->isGreaterThanOrEqualTo($this->quantity_to_order);
    }
}
