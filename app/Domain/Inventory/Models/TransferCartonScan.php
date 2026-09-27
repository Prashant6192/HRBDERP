<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A carton scanned off the lorry at the destination.
 *
 * @property int $id
 * @property int $stock_transfer_id
 * @property int $stock_transfer_line_id
 * @property int $lot_id
 * @property int $box_no
 * @property string $units
 * @property string $code
 * @property string|null $device
 * @property int|null $scanned_by
 * @property Carbon $scanned_at
 * @property Carbon|null $booked_at
 */
class TransferCartonScan extends Model
{
    protected $fillable = [
        'stock_transfer_id', 'stock_transfer_line_id', 'lot_id', 'box_no', 'units', 'code', 'device',
        'scanned_by', 'scanned_at', 'booked_at',
    ];

    protected function casts(): array
    {
        return [
            'box_no' => 'integer',
            'scanned_at' => 'datetime',
            'booked_at' => 'datetime',
        ];
    }

    public function units(): BigDecimal
    {
        return BigDecimal::of($this->units);
    }

    /**
     * @return BelongsTo<StockTransfer, $this>
     */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    /**
     * @return BelongsTo<StockTransferLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(StockTransferLine::class, 'stock_transfer_line_id');
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
    public function scanner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by');
    }
}
