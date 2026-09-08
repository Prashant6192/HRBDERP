<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Models;

use App\Domain\Approvals\Enums\ApprovalActionType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A decision someone recorded against an approval. Append-only.
 *
 * @property ApprovalActionType $action
 */
class ApprovalAction extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $fillable = [
        'approval_id', 'approval_step_id', 'user_id',
        'action', 'comment', 'ip_address', 'acted_at',
    ];

    protected function casts(): array
    {
        return [
            'action' => ApprovalActionType::class,
            'acted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Approval, $this>
     */
    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    /**
     * @return BelongsTo<ApprovalStep, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(ApprovalStep::class, 'approval_step_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
