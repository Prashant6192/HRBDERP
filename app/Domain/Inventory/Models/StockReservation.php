<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\MasterData\Models\Item;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Stock held for something without having left the shelf.
 *
 * @property int $item_id
 * @property int $warehouse_id
 * @property int|null $lot_id
 * @property string $quantity
 * @property string $consumed_quantity
 * @property ReservationStatus $status
 */
class StockReservation extends Model
{
    protected $fillable = [
        'item_id', 'warehouse_id', 'lot_id', 'quantity', 'consumed_quantity',
        'reservable_type', 'reservable_id', 'status',
        'reserved_by', 'reserved_at', 'closed_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
            'reserved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function quantity(): BigDecimal
    {
        return BigDecimal::of($this->quantity);
    }

    public function consumed(): BigDecimal
    {
        return BigDecimal::of($this->consumed_quantity);
    }

    /**
     * What is still held and not yet consumed.
     */
    public function outstanding(): BigDecimal
    {
        return $this->quantity()->minus($this->consumed());
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

    /**
     * @return MorphTo<Model, $this>
     */
    public function reservable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reservedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reserved_by');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ReservationStatus::Active->value);
    }
}
