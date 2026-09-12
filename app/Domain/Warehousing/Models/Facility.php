<?php

declare(strict_types=1);

namespace App\Domain\Warehousing\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Warehousing\Enums\FacilityCapability;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Models\User;
use Database\Factories\FacilityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A physical site: a plant, a warehouse, a depot.
 *
 * Stock lives in stores (`warehouses` rows) that belong to a facility;
 * production, receipts, transfers and people are all attributed to one.
 * What a facility may do is a set of switches, never its name.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $facility_type_id
 * @property int|null $manager_id
 * @property string|null $city
 * @property bool $can_store
 * @property bool $can_receive
 * @property bool $can_qc
 * @property bool $can_manufacture
 * @property bool $can_pack
 * @property bool $can_dispatch
 * @property bool $can_return
 * @property bool $opening_stock_enabled
 * @property bool $is_active
 */
class Facility extends Model
{
    /** @use HasFactory<FacilityFactory> */
    use HasFactory;

    use RecordsAuditTrail, SoftDeletes;

    protected $fillable = [
        'code', 'name', 'facility_type_id', 'manager_id',
        'address_line_1', 'address_line_2', 'city', 'state', 'pincode', 'country',
        'phone', 'email', 'gstin',
        'can_store', 'can_receive', 'can_qc', 'can_manufacture', 'can_pack', 'can_dispatch', 'can_return',
        'opening_stock_enabled', 'is_active', 'notes', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'can_store' => 'boolean',
            'can_receive' => 'boolean',
            'can_qc' => 'boolean',
            'can_manufacture' => 'boolean',
            'can_pack' => 'boolean',
            'can_dispatch' => 'boolean',
            'can_return' => 'boolean',
            'opening_stock_enabled' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function auditLabel(): string
    {
        return "{$this->code} — {$this->name}";
    }

    // ---- Relations ------------------------------------------------------

    /**
     * @return BelongsTo<FacilityType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(FacilityType::class, 'facility_type_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * Every store at this facility, including deactivated ones.
     *
     * @return HasMany<Warehouse, $this>
     */
    public function stores(): HasMany
    {
        return $this->hasMany(Warehouse::class)->where('is_system', false)->orderBy('sort_order')->orderBy('code');
    }

    /**
     * @return HasMany<EmployeeAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeAssignment::class);
    }

    /**
     * @return HasMany<EmployeeAssignment, $this>
     */
    public function activeAssignments(): HasMany
    {
        return $this->assignments()->active();
    }

    // ---- Capabilities ---------------------------------------------------

    public function can(FacilityCapability $capability): bool
    {
        return (bool) $this->getAttribute($capability->value);
    }

    /**
     * @return list<FacilityCapability>
     */
    public function capabilities(): array
    {
        return array_values(array_filter(
            FacilityCapability::cases(),
            fn (FacilityCapability $c): bool => $this->can($c),
        ));
    }

    /**
     * The first active store of a kind at this facility, if any.
     */
    public function storeOfKind(WarehouseType $kind): ?Warehouse
    {
        return $this->stores()->where('is_active', true)->where('type', $kind->value)->orderBy('sort_order')->orderBy('id')->first();
    }

    // ---- Scopes ---------------------------------------------------------

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeManufacturing(Builder $query): Builder
    {
        return $query->where('can_manufacture', true);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeWithCapability(Builder $query, FacilityCapability $capability): Builder
    {
        return $query->where($capability->value, true);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('can_manufacture')->orderBy('code');
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
