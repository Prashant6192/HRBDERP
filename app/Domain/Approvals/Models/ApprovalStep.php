<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stage of an approval, and who is entitled to act on it.
 *
 * A step names a permission, a role, or both. Holding either is enough unless
 * 'require_both' is set — which is how a rule like "a director who is also the
 * QC manager" is expressed without inventing a new role.
 *
 * @property int $sequence
 * @property string|null $required_permission
 * @property string|null $required_role
 * @property bool $require_both
 * @property string $status
 */
class ApprovalStep extends Model
{
    protected $fillable = [
        'approval_id', 'sequence', 'name',
        'required_permission', 'required_role', 'require_both',
        'status', 'acted_by', 'acted_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'require_both' => 'boolean',
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
     * @return BelongsTo<User, $this>
     */
    public function actedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by');
    }

    /**
     * Whether a given user may decide this step.
     *
     * This is the authorisation decision for the whole approval engine, so it
     * is deliberately explicit rather than clever.
     */
    public function isActionableBy(User $user): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        $hasPermission = $this->required_permission === null
            || $user->can($this->required_permission);

        $hasRole = $this->required_role === null
            || $user->hasRole($this->required_role);

        // A step with neither requirement would be actionable by anyone, which
        // is never what an approval step is for.
        if ($this->required_permission === null && $this->required_role === null) {
            return false;
        }

        if ($this->require_both) {
            return $hasPermission && $hasRole;
        }

        // Only the stated requirements count towards "either".
        return ($this->required_permission !== null && $hasPermission)
            || ($this->required_role !== null && $hasRole);
    }
}
