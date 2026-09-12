<?php

declare(strict_types=1);

namespace App\Domain\Warehousing\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\MasterData\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Store-specific reorder thresholds for one item.
 *
 * Absent (or null) figures fall back to the item's own thresholds, so a
 * store only needs a row where its needs differ from the company default.
 *
 * @property int $warehouse_id
 * @property int $item_id
 * @property string|null $minimum_stock
 * @property string|null $reorder_level
 * @property string|null $moderate_multiplier
 */
class StoreItemLevel extends Model
{
    use RecordsAuditTrail;

    protected $fillable = ['warehouse_id', 'item_id', 'minimum_stock', 'reorder_level', 'moderate_multiplier', 'updated_by'];

    public function auditLabel(): string
    {
        return "store #{$this->warehouse_id} / item #{$this->item_id}";
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
