<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Inventory\Enums\StockCountStatus;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A physical count of one store: the system's quantities frozen when it
 * started, what was found on the shelf, and — once someone other than the
 * counter approves it — the adjustments that reconcile the two.
 *
 * @property int $id
 * @property string $number
 * @property int $warehouse_id
 * @property StockCountStatus $status
 * @property string|null $notes
 * @property int|null $started_by
 * @property CarbonImmutable $started_at
 * @property int|null $submitted_by
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 */
class StockCount extends Model
{
    use RecordsAuditTrail;

    protected $fillable = [
        'number', 'warehouse_id', 'status', 'notes', 'started_by', 'started_at',
        'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => StockCountStatus::class,
            'started_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function auditLabel(): string
    {
        return $this->number;
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return HasMany<StockCountLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class)->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /** @return BelongsTo<User, $this> */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
