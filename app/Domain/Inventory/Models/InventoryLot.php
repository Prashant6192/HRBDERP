<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\MasterData\Models\Item;
use App\Domain\Procurement\Models\Vendor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\InventoryLotFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One batch of one item.
 *
 * @property int $id
 * @property int $item_id
 * @property string $batch_number
 * @property LotQcStatus $qc_status
 * @property CarbonImmutable|null $expiry_at
 * @property CarbonImmutable|null $manufactured_at
 * @property CarbonImmutable|null $received_at
 * @property string $initial_quantity
 * @property string|null $unit_cost
 */
class InventoryLot extends Model
{
    /** @use HasFactory<InventoryLotFactory> */
    use HasFactory;

    use RecordsAuditTrail;

    protected $fillable = [
        'item_id', 'batch_number', 'supplier_batch_ref', 'vendor_id',
        'manufactured_at', 'received_at', 'expiry_at',
        'qc_status', 'qc_decided_at', 'qc_decided_by',
        'initial_quantity', 'unit_cost',
        'source_type', 'source_id', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'qc_status' => LotQcStatus::class,
            'manufactured_at' => 'date',
            'received_at' => 'date',
            'expiry_at' => 'date',
            'qc_decided_at' => 'datetime',
        ];
    }

    public function auditLabel(): string
    {
        return $this->batch_number;
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function qcDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'qc_decided_by');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<StockBalance, $this>
     */
    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class, 'lot_id');
    }

    public function isExpired(?CarbonInterface $asOf = null): bool
    {
        if ($this->expiry_at === null) {
            return false;
        }

        return $this->expiry_at->lt(($asOf ?? now())->startOfDay());
    }

    /**
     * Whether stock in this lot may be issued: quality has released it and
     * it has not passed its expiry.
     */
    public function isReleasable(?CarbonInterface $asOf = null): bool
    {
        return $this->qc_status->isReleasable() && ! $this->isExpired($asOf);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeReleasable(Builder $query, ?CarbonInterface $asOf = null): Builder
    {
        return $query
            ->whereIn('qc_status', [LotQcStatus::Approved->value, LotQcStatus::NotRequired->value])
            ->where(function (Builder $q) use ($asOf): void {
                $q->whereNull('expiry_at')->orWhereDate('expiry_at', '>=', ($asOf ?? now())->toDateString());
            });
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->whereNotNull('expiry_at')
            ->whereDate('expiry_at', '<=', now()->addDays($days)->toDateString());
    }
}
