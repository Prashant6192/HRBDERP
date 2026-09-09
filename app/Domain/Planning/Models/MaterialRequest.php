<?php

declare(strict_types=1);

namespace App\Domain\Planning\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Planning\Enums\MaterialRequestStatus;
use App\Domain\Planning\Enums\StoreKind;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Production Material Request: what one store must provide for a plan,
 * and how much of it must be bought first.
 *
 * @property int $id
 * @property string $number
 * @property int $production_plan_id
 * @property StoreKind $store_kind
 * @property int $warehouse_id
 * @property MaterialRequestStatus $status
 * @property CarbonImmutable|null $needed_by
 * @property CarbonImmutable $requested_at
 * @property ProductionPlan $plan
 * @property Warehouse $warehouse
 */
class MaterialRequest extends Model
{
    use RecordsAuditTrail;

    protected $fillable = [
        'number', 'production_plan_id', 'store_kind', 'warehouse_id', 'status', 'needed_by', 'notes',
        'requested_by', 'requested_at', 'fulfilled_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'store_kind' => StoreKind::class,
            'status' => MaterialRequestStatus::class,
            'needed_by' => 'date',
            'requested_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function auditLabel(): string
    {
        return $this->number;
    }

    /**
     * @return BelongsTo<ProductionPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProductionPlan::class, 'production_plan_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * @return HasMany<MaterialRequestLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(MaterialRequestLine::class, 'material_request_id')->orderBy('line_no');
    }

    /**
     * @return HasMany<GoodsReceipt, $this>
     */
    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class, 'material_request_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [MaterialRequestStatus::Open->value, MaterialRequestStatus::PartiallyReceived->value]);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term): void {
            $q->where('number', 'ilike', "%{$term}%")
                ->orWhereHas('plan', fn (Builder $p) => $p->where('number', 'ilike', "%{$term}%")
                    ->orWhereHas('formula', fn (Builder $f) => $f->where('name', 'ilike', "%{$term}%")));
        });
    }
}
