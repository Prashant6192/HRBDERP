<?php

declare(strict_types=1);

namespace App\Domain\Manufacturing\Models;

use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\MasterData\Models\Item;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One scan at the kettle: this code, for this batch, was allowed or
 * blocked, and why. Append-only.
 *
 * @property int $id
 * @property int $manufacturing_order_id
 * @property string $code
 * @property int|null $item_id
 * @property int|null $lot_id
 * @property string $verdict
 * @property list<string> $reasons
 * @property int|null $scanned_by
 * @property CarbonImmutable $scanned_at
 */
class ManufacturingOrderScan extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['manufacturing_order_id', 'code', 'item_id', 'lot_id', 'verdict', 'reasons', 'scanned_by', 'scanned_at'];

    protected function casts(): array
    {
        return ['reasons' => 'array', 'scanned_at' => 'immutable_datetime'];
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

    /** @return BelongsTo<User, $this> */
    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by');
    }
}
