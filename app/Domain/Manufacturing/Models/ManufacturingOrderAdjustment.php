<?php

declare(strict_types=1);

namespace App\Domain\Manufacturing\Models;

use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\InventoryTransaction;
use App\Domain\MasterData\Models\Item;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Material a batch gave back or lost after it was issued: a return goes to
 * the store through the ledger; wastage is recorded so the batch's true
 * consumption and cost are known.
 *
 * @property int $id
 * @property int $manufacturing_order_id
 * @property int $item_id
 * @property int|null $lot_id
 * @property string $kind
 * @property string $quantity
 * @property string|null $reason
 * @property int|null $inventory_transaction_id
 * @property int|null $recorded_by
 * @property CarbonImmutable $recorded_at
 */
class ManufacturingOrderAdjustment extends Model
{
    public const UPDATED_AT = null;

    public const RETURN = 'return';

    public const WASTAGE = 'wastage';

    protected $fillable = ['manufacturing_order_id', 'item_id', 'lot_id', 'kind', 'quantity', 'reason', 'inventory_transaction_id', 'recorded_by', 'recorded_at'];

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ManufacturingOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ManufacturingOrder::class, 'manufacturing_order_id');
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

    /** @return BelongsTo<InventoryTransaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class, 'inventory_transaction_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
