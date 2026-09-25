<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Marketplace\Enums\LabelBatchStatus;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One day's labels for one brand on one marketplace, shipping from one
 * store. The agency adds files to it through the morning and closes it
 * when that is all; the depot prints from it.
 *
 * @property int $id
 * @property string $number
 * @property int $brand_id
 * @property int $marketplace_id
 * @property int $facility_id
 * @property int $warehouse_id
 * @property Carbon $for_date
 * @property LabelBatchStatus $status
 * @property Carbon|null $closed_at
 */
class LabelBatch extends Model
{
    use RecordsAuditTrail;

    protected $fillable = [
        'number', 'brand_id', 'marketplace_id', 'facility_id', 'warehouse_id', 'for_date', 'status', 'notes',
        'uploaded_by', 'closed_at', 'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'for_date' => 'date',
            'status' => LabelBatchStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    public function auditLabel(): string
    {
        return $this->number;
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<Marketplace, $this>
     */
    public function marketplace(): BelongsTo
    {
        return $this->belongsTo(Marketplace::class);
    }

    /**
     * @return BelongsTo<Facility, $this>
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return HasMany<LabelFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(LabelFile::class)->orderBy('id');
    }

    /**
     * @return HasMany<Shipment, $this>
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    /**
     * @return HasMany<LabelPrint, $this>
     */
    public function prints(): HasMany
    {
        return $this->hasMany(LabelPrint::class)->latest('id');
    }

    public function isOpen(): bool
    {
        return $this->status === LabelBatchStatus::Open;
    }
}
