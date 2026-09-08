<?php

declare(strict_types=1);

namespace App\Domain\Warehousing\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
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
 * @property int $id
 * @property string $code
 * @property string $name
 * @property WarehouseType $type
 * @property bool $is_quarantine
 */
class Warehouse extends Model
{
    /** @use HasFactory<WarehouseFactory> */
    use HasFactory;

    use RecordsAuditTrail, SoftDeletes;

    protected $fillable = [
        'code', 'name', 'type', 'manager_id',
        'address_line_1', 'address_line_2', 'city', 'state', 'pincode',
        'country', 'gstin', 'is_quarantine', 'is_active', 'notes',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => WarehouseType::class,
            'is_quarantine' => 'boolean',
            'is_active' => 'boolean',
        ];
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
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Warehouses whose stock may be committed to production or sales.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeAvailableForIssue(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_quarantine', false);
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
