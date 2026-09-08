<?php

declare(strict_types=1);

namespace App\Domain\Audit\Models;

use App\Domain\Audit\Enums\AuditAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * An append-only record of something that happened.
 *
 * Immutability is enforced in three layers, because an audit trail that the
 * application can quietly rewrite is not evidence of anything:
 *
 *   1. This model refuses to update or delete (below).
 *   2. There is no controller, route or policy that permits either.
 *   3. Production revokes UPDATE and DELETE on audit_logs from the application
 *      database role — see SECURITY_ARCHITECTURE.md. This is the layer that
 *      matters, since the first two only bind code that goes through Eloquent.
 *
 * The user is recorded twice: as a foreign key, and as a name and email copied
 * at the time of the action. The copy is what survives if the user record is
 * later renamed or removed, and it is what a reader of a two-year-old entry
 * actually needs.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'audit_logs';

    protected $fillable = [
        'user_id', 'user_name', 'user_email',
        'action', 'description',
        'auditable_type', 'auditable_id', 'auditable_label',
        'old_values', 'new_values',
        'ip_address', 'user_agent', 'session_id', 'route',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new RuntimeException('Audit log entries are immutable and cannot be updated.');
        });

        static::deleting(static function (): never {
            throw new RuntimeException('Audit log entries are immutable and cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The fields that actually changed, paired old-to-new for display.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function changes(): array
    {
        $old = $this->old_values ?? [];
        $new = $this->new_values ?? [];

        $changes = [];

        foreach (array_keys($old + $new) as $field) {
            $before = $old[$field] ?? null;
            $after = $new[$field] ?? null;

            if ($before !== $after) {
                $changes[$field] = ['old' => $before, 'new' => $after];
            }
        }

        return $changes;
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForEntity(Builder $query, Model $entity): Builder
    {
        return $query
            ->where('auditable_type', $entity::class)
            ->where('auditable_id', $entity->getKey());
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeAction(Builder $query, AuditAction|string $action): Builder
    {
        return $query->where('action', $action instanceof AuditAction ? $action->value : $action);
    }
}
