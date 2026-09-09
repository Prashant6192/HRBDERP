<?php

declare(strict_types=1);

namespace App\Domain\Quality\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\MasterData\Models\Item;
use App\Domain\Procurement\Models\GoodsReceiptLine;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quality's decision on one lot.
 *
 * @property int $id
 * @property string $number
 * @property int $lot_id
 * @property int $item_id
 * @property string $quantity
 * @property LotQcStatus $status
 * @property int $destination_warehouse_id
 */
class QcInspection extends Model
{
    use RecordsAuditTrail;

    protected $fillable = [
        'number', 'lot_id', 'item_id', 'goods_receipt_line_id', 'quantity',
        'status', 'destination_warehouse_id', 'decided_by', 'decided_at',
        'remarks', 'parameters', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => LotQcStatus::class,
            'decided_at' => 'datetime',
            'parameters' => 'array',
        ];
    }

    public function auditLabel(): string
    {
        return $this->number;
    }

    public function quantity(): BigDecimal
    {
        return BigDecimal::of($this->quantity);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [LotQcStatus::Pending, LotQcStatus::OnHold], strict: true);
    }

    /**
     * @return BelongsTo<InventoryLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'lot_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    /**
     * @return BelongsTo<GoodsReceiptLine, $this>
     */
    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptLine::class, 'goods_receipt_line_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [LotQcStatus::Pending->value, LotQcStatus::OnHold->value]);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term): void {
            $q->where('number', 'ilike', "%{$term}%")
                ->orWhereHas('lot', fn (Builder $l) => $l->where('batch_number', 'ilike', "%{$term}%"))
                ->orWhereHas('item', fn (Builder $i) => $i->where('name', 'ilike', "%{$term}%")->orWhere('code', 'ilike', "%{$term}%"));
        });
    }
}
