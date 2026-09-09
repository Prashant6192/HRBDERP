<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Procurement\Enums\GoodsReceiptStatus;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A delivery, as entered from the delivery note.
 *
 * @property int $id
 * @property string $number
 * @property GoodsReceiptStatus $status
 * @property CarbonImmutable $received_at
 * @property int $warehouse_id
 */
class GoodsReceipt extends Model
{
    use RecordsAuditTrail;

    protected $fillable = [
        'number', 'vendor_id', 'warehouse_id', 'received_at', 'invoice_ref',
        'status', 'notes', 'received_by', 'created_by', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => GoodsReceiptStatus::class,
            'received_at' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    public function auditLabel(): string
    {
        return $this->number;
    }

    /**
     * @return HasMany<GoodsReceiptLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class, 'goods_receipt_id');
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
                ->orWhere('invoice_ref', 'ilike', "%{$term}%")
                ->orWhereHas('vendor', fn (Builder $v) => $v->where('name', 'ilike', "%{$term}%"));
        });
    }
}
