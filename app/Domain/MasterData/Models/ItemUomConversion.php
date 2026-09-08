<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Models;

use App\Domain\Measurement\Models\Uom;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A conversion factor that belongs to one item rather than to the units.
 *
 * "One carton holds 24 pieces" is true of a particular bottle, not of cartons
 * in general, so it cannot live on the unit itself.
 *
 * @property int $item_id
 * @property int $from_uom_id
 * @property int $to_uom_id
 * @property string $factor
 */
class ItemUomConversion extends Model
{
    protected $fillable = ['item_id', 'from_uom_id', 'to_uom_id', 'factor'];

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<Uom, $this>
     */
    public function fromUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'from_uom_id');
    }

    /**
     * @return BelongsTo<Uom, $this>
     */
    public function toUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'to_uom_id');
    }
}
