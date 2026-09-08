<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Models;

use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A request for something to be approved, and where it has got to.
 *
 * @property string $workflow_key
 * @property ApprovalStatus $status
 * @property int $current_step
 */
class Approval extends Model
{
    protected $fillable = [
        'approvable_type', 'approvable_id', 'workflow_key', 'status',
        'current_step', 'requested_by', 'requested_at', 'completed_at',
        'request_note', 'context',
    ];

    protected function casts(): array
    {
        return [
            'status' => ApprovalStatus::class,
            'context' => 'array',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
            'current_step' => 'integer',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<ApprovalStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class)->orderBy('sequence');
    }

    /**
     * @return HasMany<ApprovalAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(ApprovalAction::class)->orderBy('acted_at');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * The step currently awaiting a decision, if any.
     */
    public function currentStep(): ?ApprovalStep
    {
        return $this->steps->firstWhere('sequence', $this->current_step);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ApprovalStatus::Pending->value,
            ApprovalStatus::Returned->value,
        ]);
    }
}
