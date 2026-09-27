<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One print of a batch's carton stickers.
 *
 * @property int $id
 * @property int $lot_id
 * @property string $format
 * @property string|null $printer
 * @property int $first_box
 * @property int $last_box
 * @property int $copies
 * @property int|null $printed_by
 * @property Carbon $printed_at
 */
class CartonLabelPrint extends Model
{
    protected $fillable = ['lot_id', 'format', 'printer', 'first_box', 'last_box', 'copies', 'printed_by', 'printed_at'];

    protected function casts(): array
    {
        return [
            'first_box' => 'integer',
            'last_box' => 'integer',
            'copies' => 'integer',
            'printed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<InventoryLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'lot_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function printedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by');
    }
}
