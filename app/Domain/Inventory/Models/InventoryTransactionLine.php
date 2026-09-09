<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\MasterData\Models\Item;
use App\Domain\Warehousing\Models\Warehouse;
use App\Domain\Warehousing\Models\WarehouseLocation;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * @property int $item_id
 * @property int|null $lot_id
 * @property int $warehouse_id
 * @property string $quantity Signed, in the item's stock unit.
 * @property string|null $unit_cost
 */
class InventoryTransactionLine extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'inventory_transaction_id', 'item_id', 'lot_id', 'warehouse_id',
        'location_id', 'quantity', 'unit_cost',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new RuntimeException('Inventory transaction lines are immutable.');
        });

        static::deleting(static function (): never {
            throw new RuntimeException('Inventory transaction lines are immutable.');
        });
    }

    public function quantity(): BigDecimal
    {
        return BigDecimal::of($this->quantity);
    }

    /**
     * @return BelongsTo<InventoryTransaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class, 'inventory_transaction_id');
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
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * @return BelongsTo<WarehouseLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
    }
}
