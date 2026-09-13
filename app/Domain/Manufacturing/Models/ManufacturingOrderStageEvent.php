<?php

declare(strict_types=1);

namespace App\Domain\Manufacturing\Models;

use App\Domain\Manufacturing\Enums\ProductionStage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reading from the floor: at this moment the batch was at this stage,
 * this far through. Append-only; the order's current stage is the latest.
 *
 * @property int $id
 * @property int $manufacturing_order_id
 * @property ProductionStage $stage
 * @property int $progress
 * @property string|null $note
 * @property int|null $recorded_by
 * @property CarbonImmutable $recorded_at
 */
class ManufacturingOrderStageEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['manufacturing_order_id', 'stage', 'progress', 'note', 'recorded_by', 'recorded_at'];

    protected function casts(): array
    {
        return [
            'stage' => ProductionStage::class,
            'progress' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ManufacturingOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ManufacturingOrder::class, 'manufacturing_order_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
