<?php

declare(strict_types=1);

namespace App\Domain\Warehousing\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Warehousing\Enums\AssignmentStatus;
use App\Models\User;
use Database\Factories\EmployeeAssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where an employee works.
 *
 * One user record; any number of assignments. An assignment names a
 * facility and, optionally, one store within it. The primary assignment is
 * the one screens default to. Authorisation for a stock action is the
 * role permission AND an active assignment covering the facility (or the
 * specific store, when the assignment is that narrow).
 *
 * @property int $id
 * @property int $user_id
 * @property int $facility_id
 * @property int|null $store_id
 * @property bool $is_primary
 * @property AssignmentStatus $status
 */
class EmployeeAssignment extends Model
{
    /** @use HasFactory<EmployeeAssignmentFactory> */
    use HasFactory;

    use RecordsAuditTrail;

    protected $fillable = [
        'user_id', 'facility_id', 'store_id', 'is_primary', 'designation',
        'effective_from', 'effective_to', 'status', 'assigned_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'status' => AssignmentStatus::class,
        ];
    }

    public function auditLabel(): string
    {
        $store = $this->store_id ? " / store #{$this->store_id}" : '';

        return "user #{$this->user_id} → facility #{$this->facility_id}{$store}";
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
    public function store(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'store_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', AssignmentStatus::Active->value);
    }

    public function coversWholeFacility(): bool
    {
        return $this->store_id === null;
    }
}
