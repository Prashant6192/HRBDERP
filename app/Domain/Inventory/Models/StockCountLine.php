<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\MasterData\Models\Item;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $stock_count_id
 * @property int $item_id
 * @property int|null $lot_id
 * @property string $system_quantity
 * @property string|null $counted_quantity
 * @property string|null $note
 * @property int|null $counted_by
 * @property CarbonImmutable|null $counted_at
 * @property int|null $inventory_transaction_id
 */
class StockCountLine extends Model
{
    protected $fillable = [
        'stock_count_id', 'item_id', 'lot_id', 'system_quantity', 'counted_quantity', 'note',
        'counted_by', 'counted_at', 'inventory_transaction_id',
    ];

    protected function casts(): array
    {
        return ['counted_at' => 'immutable_datetime'];
    }

    public function variance(): ?BigDecimal
    {
        return $this->counted_quantity === null ? null : BigDecimal::of($this->counted_quantity)->minus(BigDecimal::of($this->system_quantity));
    }

    /** @return BelongsTo<StockCount, $this> */
    public function count(): BelongsTo
    {
        return $this->belongsTo(StockCount::class, 'stock_count_id');
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<InventoryLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'lot_id');
    }

    /** @return BelongsTo<User, $this> */
    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }
}
