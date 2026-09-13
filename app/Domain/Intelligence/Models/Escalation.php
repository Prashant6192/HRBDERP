<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One escalation raised: which exception, at which level, to whom, when,
 * and when the condition cleared.
 *
 * @property int $id
 * @property string $exception_key
 * @property string $rule
 * @property int $level
 * @property string $title
 * @property string|null $href
 * @property list<string> $roles
 * @property int $recipients
 * @property CarbonImmutable $escalated_at
 * @property CarbonImmutable|null $resolved_at
 */
class Escalation extends Model
{
    protected $fillable = [
        'exception_key', 'rule', 'level', 'title', 'href', 'roles', 'recipients', 'escalated_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'roles' => 'array',
            'recipients' => 'integer',
            'escalated_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeStanding(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }
}
