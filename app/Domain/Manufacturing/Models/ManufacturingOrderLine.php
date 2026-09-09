<?php

declare(strict_types=1);

namespace App\Domain\Manufacturing\Models;

use App\Domain\MasterData\Models\Item;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Planning\Enums\StoreKind;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One material an order takes: planned, held, and finally used.
 *
 * @property int $id
 * @property int $manufacturing_order_id
 * @property int $line_no
 * @property StoreKind $store_kind
 * @property int $item_id
 * @property int $uom_id
 * @property string|null $percentage
 * @property bool $is_qs
 * @property bool $as_required
 * @property string $planned_quantity
 * @property string $reserved_quantity
 * @property string $consumed_quantity
 * @property Item $item
 * @property Uom $uom
 */
class ManufacturingOrderLine extends Model
{
    protected $fillable = [
        'manufacturing_order_id', 'line_no', 'store_kind', 'item_id', 'uom_id', 'percentage', 'is_qs', 'as_required',
        'planned_quantity', 'reserved_quantity', 'consumed_quantity',
    ];

    protected function casts(): array
    {
        return [
            'store_kind' => StoreKind::class,
            'line_no' => 'integer',
            'is_qs' => 'boolean',
            'as_required' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ManufacturingOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ManufacturingOrder::class, 'manufacturing_order_id');
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

    public function plannedQuantity(): BigDecimal
    {
        return BigDecimal::of($this->planned_quantity);
    }
}
