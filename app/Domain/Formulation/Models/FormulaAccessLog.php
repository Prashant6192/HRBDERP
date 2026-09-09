<?php

declare(strict_types=1);

namespace App\Domain\Formulation\Models;

use App\Domain\Formulation\Enums\FormulaAccessAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The formula access trail. Append-only: rows are never updated or deleted.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $formula_id
 * @property int|null $formula_version_id
 * @property FormulaAccessAction $action
 * @property CarbonImmutable $occurred_at
 */
class FormulaAccessLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'formula_id', 'formula_version_id', 'action',
        'ip_address', 'user_agent', 'context', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'action' => FormulaAccessAction::class,
            'context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Formula access log entries are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Formula access log entries cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Formula, $this>
     */
    public function formula(): BelongsTo
    {
        return $this->belongsTo(Formula::class, 'formula_id');
    }
}
