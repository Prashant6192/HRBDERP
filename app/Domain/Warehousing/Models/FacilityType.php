<?php

declare(strict_types=1);

namespace App\Domain\Warehousing\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of facility — plant, warehouse, distribution centre, office.
 *
 * Editable: the administrator can add or rename types. Each carries the
 * capabilities a new facility of that type starts with; the facility can
 * then switch any of them on or off.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property array<string, bool>|null $default_capabilities
 * @property bool $is_system
 * @property bool $is_active
 */
class FacilityType extends Model
{
    use RecordsAuditTrail;

    protected $fillable = ['code', 'name', 'description', 'default_capabilities', 'is_system', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'default_capabilities' => 'array',
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
     * @return HasMany<Facility, $this>
     */
    public function facilities(): HasMany
    {
        return $this->hasMany(Facility::class);
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
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function defaultsManufacturing(): bool
    {
        return (bool) ($this->default_capabilities['can_manufacture'] ?? false);
    }
}
