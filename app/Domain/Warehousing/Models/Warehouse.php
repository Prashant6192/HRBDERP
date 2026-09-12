<?php

declare(strict_types=1);

namespace App\Domain\Warehousing\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Inventory\Models\InventoryTransactionLine;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Models\User;
use Database\Factories\WarehouseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A store: one physical stock-holding place inside a facility.
 *
 * The table keeps its historical name because every stock table points at
 * it; to the user it is a "store". Its behaviour (`type`, `is_quarantine`)
 * follows from its store category and is kept in step whenever it is
 * saved, so workflows written against the type keep working while the
 * administrator only ever thinks in categories.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int|null $facility_id
 * @property int|null $store_category_id
 * @property WarehouseType $type
 * @property bool $is_quarantine
 * @property bool $is_active
 * @property bool $is_system
 */
class Warehouse extends Model
{
    /** @use HasFactory<WarehouseFactory> */
    use HasFactory;

    use RecordsAuditTrail, SoftDeletes;

    protected $fillable = [
        'code', 'name', 'facility_id', 'store_category_id', 'type', 'manager_id',
        'address_line_1', 'address_line_2', 'city', 'state', 'pincode',
        'country', 'gstin', 'is_quarantine', 'is_active', 'is_system', 'sort_order', 'notes',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => WarehouseType::class,
            'is_quarantine' => 'boolean',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Category and behaviour are one thing seen from two sides. A store
        // saved with a category takes its kind from it; one saved with only
        // a type (the older screens, the factories) is filed under the
        // matching category so the facility screens see it too.
        static::saving(static function (self $store): void {
            if ($store->store_category_id !== null) {
                $category = $store->relationLoaded('category') && $store->category?->id === $store->store_category_id
                    ? $store->category
                    : StoreCategory::query()->find($store->store_category_id);

                if ($category !== null) {
                    $store->applyCategory($category);
                }

                return;
            }

            $kind = $store->type instanceof WarehouseType ? $store->type : WarehouseType::tryFrom((string) $store->type);

            if ($kind !== null) {
                $store->store_category_id = StoreCategory::ofKind($kind)?->id;
            }
        });
    }

    public function auditLabel(): string
    {
        return "{$this->code} — {$this->name}";
    }

    /**
     * @return HasMany<WarehouseLocation, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(WarehouseLocation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * @return BelongsTo<Facility, $this>
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /**
     * @return BelongsTo<StoreCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(StoreCategory::class, 'store_category_id');
    }

    /**
     * @return HasMany<StockBalance, $this>
     */
    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class, 'warehouse_id');
    }

    /**
     * @return HasMany<EmployeeAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeAssignment::class, 'store_id');
    }

    /**
     * @return HasMany<StoreItemLevel, $this>
     */
    public function itemLevels(): HasMany
    {
        return $this->hasMany(StoreItemLevel::class, 'warehouse_id');
    }

    /**
     * Take the behaviour a category implies. Called on save, and by code
     * that runs with model events muted.
     */
    public function applyCategory(StoreCategory $category): static
    {
        $this->store_category_id = $category->id;
        $this->type = $category->kind;
        $this->is_quarantine = $category->kind->holdsQuarantinedStock() || (bool) $this->is_quarantine;

        return $this;
    }

    /**
     * The short badge shown for this store: RM, PM, FG, QUAR…
     */
    public function badge(): string
    {
        return $this->relationLoaded('category') && $this->category !== null
            ? $this->category->badge
            : $this->type->badge();
    }

    /**
     * Whether anything has ever moved through, or been held in, this store.
     * A store with history is deactivated, never deleted.
     */
    public function hasOperationalHistory(): bool
    {
        return InventoryTransactionLine::query()->where('warehouse_id', $this->id)->exists()
            || $this->balances()->exists();
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_system', false);
    }

    /**
     * Stores at one facility.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeAtFacility(Builder $query, int|Facility|null $facility): Builder
    {
        if ($facility === null) {
            return $query;
        }

        return $query->where('facility_id', $facility instanceof Facility ? $facility->id : $facility);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOfKind(Builder $query, WarehouseType $kind): Builder
    {
        return $query->where('type', $kind->value);
    }

    /**
     * Warehouses whose stock may be committed to production or sales.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeAvailableForIssue(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_quarantine', false)->where('is_system', false);
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

        return $query->where(function (Builder $query) use ($term): void {
            $query->where('code', 'ilike', "%{$term}%")
                ->orWhere('name', 'ilike', "%{$term}%")
                ->orWhere('city', 'ilike', "%{$term}%");
        });
    }
}
