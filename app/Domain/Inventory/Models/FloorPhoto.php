<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A photo taken on the floor and attached to a batch, an order or a
 * count: a damaged drum, a label, a reading on a gauge.
 *
 * @property int $id
 * @property string $subject_type
 * @property int $subject_id
 * @property string $path
 * @property string|null $note
 * @property int|null $taken_by
 * @property CarbonImmutable $taken_at
 */
class FloorPhoto extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['subject_type', 'subject_id', 'path', 'note', 'taken_by', 'taken_at'];

    protected function casts(): array
    {
        return ['taken_at' => 'immutable_datetime'];
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function takenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taken_by');
    }
}
