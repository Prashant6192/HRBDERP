<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A movement of stock from a store at one facility to a store at another.
 *
 * The document carries the intent and the sign-offs; the stock itself moves
 * only through ledger postings made by StockTransferService, so the
 * balances can always be traced back to who dispatched and who received.
 *
 * @property int $id
 * @property string $number
 * @property int $source_facility_id
 * @property int $source_warehouse_id
 * @property int $destination_facility_id
 * @property int $destination_warehouse_id
 * @property StockTransferStatus $status
 * @property bool $requires_inspection
 */
class StockTransfer extends Model
{
    use RecordsAuditTrail, SoftDeletes;

    protected $fillable = [
        'number', 'source_facility_id', 'source_warehouse_id', 'destination_facility_id', 'destination_warehouse_id',
        'status', 'requires_inspection', 'expected_at', 'reason', 'notes', 'vehicle_ref',
        'requested_by', 'requested_at', 'approved_by', 'approved_at', 'dispatched_by', 'dispatched_at',
        'received_by', 'received_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => StockTransferStatus::class,
            'requires_inspection' => 'boolean',
            'expected_at' => 'date',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function auditLabel(): string
    {
        return $this->number;
    }

    /**
     * @return HasMany<StockTransferLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class)->orderBy('line_no');
    }

    /**
     * @return BelongsTo<Facility, $this>
     */
    public function sourceFacility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'source_facility_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function sourceStore(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    /**
     * @return BelongsTo<Facility, $this>
     */
    public function destinationFacility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'destination_facility_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function destinationStore(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function dispatcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * @return MorphMany<StockReservation, $this>
     */
    public function reservations(): MorphMany
    {
        return $this->morphMany(StockReservation::class, 'reservable');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            StockTransferStatus::Received->value,
            StockTransferStatus::Discrepancy->value,
            StockTransferStatus::Rejected->value,
            StockTransferStatus::Cancelled->value,
        ]);
    }

    /**
     * Transfers touching a facility, either way.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForFacility(Builder $query, int $facilityId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('source_facility_id', $facilityId)
            ->orWhere('destination_facility_id', $facilityId));
    }
}
