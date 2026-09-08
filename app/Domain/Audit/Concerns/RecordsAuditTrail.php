<?php

declare(strict_types=1);

namespace App\Domain\Audit\Concerns;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditLogger;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Applied to any model whose changes belong in the audit trail.
 *
 * The trait records creation, update, deletion and restoration automatically.
 * Business events that are not model writes — an approval, a formula being
 * viewed, stock being adjusted — are recorded explicitly through AuditLogger,
 * because only the code performing them knows what they mean.
 *
 * A model may narrow what is captured:
 *
 *   protected array $auditExclude = ['last_login_at'];
 *   protected array $auditOnly = ['name', 'code'];   // if set, wins
 *
 * Attributes hidden on the model (passwords, tokens, the formula PIN hash) are
 * never recorded, whatever these lists say.
 */
trait RecordsAuditTrail
{
    public static function bootRecordsAuditTrail(): void
    {
        static::created(static function ($model): void {
            $model->writeAuditEntry(
                AuditAction::Created,
                newValues: $model->auditableAttributes($model->getAttributes()),
            );
        });

        static::updated(static function ($model): void {
            $changed = $model->auditableAttributes($model->getChanges());

            // An update that touched nothing auditable — a timestamp bump, a
            // login counter — is not worth an entry.
            if ($changed === []) {
                return;
            }

            $original = array_intersect_key(
                $model->auditableAttributes($model->getOriginal()),
                $changed,
            );

            $model->writeAuditEntry(
                AuditAction::Updated,
                oldValues: $original,
                newValues: $changed,
            );
        });

        static::deleted(static function ($model): void {
            $model->writeAuditEntry(
                AuditAction::Deleted,
                oldValues: $model->auditableAttributes($model->getOriginal()),
            );
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(static function ($model): void {
                $model->writeAuditEntry(AuditAction::Restored);
            });
        }
    }

    /**
     * @return MorphMany<AuditLog, $this>
     */
    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    protected function writeAuditEntry(AuditAction $action, array $oldValues = [], array $newValues = []): void
    {
        app(AuditLogger::class)->log(
            action: $action,
            entity: $this,
            oldValues: $oldValues,
            newValues: $newValues,
        );
    }

    /**
     * Strip attributes that must never reach the audit table.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function auditableAttributes(array $attributes): array
    {
        $only = property_exists($this, 'auditOnly') ? $this->auditOnly : [];

        if ($only !== []) {
            $attributes = array_intersect_key($attributes, array_flip($only));
        }

        $excluded = [
            ...$this->getHidden(),
            ...(property_exists($this, 'auditExclude') ? $this->auditExclude : []),
            'created_at',
            'updated_at',
        ];

        return array_diff_key($attributes, array_flip($excluded));
    }
}
