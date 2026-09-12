<?php

declare(strict_types=1);

namespace App\Domain\Warehousing\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Warehousing\Enums\WarehouseType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of store: raw material, packaging, finished goods, quarantine…
 *
 * The category is what the administrator sees and edits — its name, badge,
 * icon and whether it is offered when a facility is set up. The `kind`
 * behind it is the behaviour the workflows rely on (which store receives
 * QC-passed raw material, which one holds quarantined stock) and is fixed
 * once the category is in use.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $badge
 * @property WarehouseType $kind
 * @property bool $is_system
 * @property bool $is_active
 */
class StoreCategory extends Model
{
    use RecordsAuditTrail;

    protected $fillable = ['code', 'name', 'badge', 'kind', 'icon', 'color', 'description', 'is_system', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'kind' => WarehouseType::class,
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function auditLabel(): string
    {
        return "{$this->code} — {$this->name}";
    }

    /**
     * @return HasMany<Warehouse, $this>
     */
    public function stores(): HasMany
    {
        return $this->hasMany(Warehouse::class, 'store_category_id');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Categories a user may pick for a store: active, and not the in-transit
     * position the system keeps for itself.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('kind', '!=', WarehouseType::InTransit->value);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public static function ofKind(WarehouseType $kind): ?self
    {
        return self::query()->where('kind', $kind->value)->orderBy('sort_order')->first();
    }

    public function isInUse(): bool
    {
        return $this->stores()->withTrashed()->exists();
    }
}
