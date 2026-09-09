<?php

declare(strict_types=1);

namespace App\Domain\Formulation\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A short-lived grant to see recipes, earned by clearing the second factor.
 *
 * Bound to the browser session that earned it (session_id holds a hash of
 * a per-session token), so another session of the same account must
 * verify for itself.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $session_id
 * @property CarbonImmutable $unlocked_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $revoked_at
 */
class FormulaUnlock extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'session_id', 'unlocked_at', 'expires_at', 'revoked_at', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'unlocked_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isValid(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function minutesRemaining(): int
    {
        return max(0, (int) ceil(now()->diffInSeconds($this->expires_at, false) / 60));
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeValid(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }
}
