<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Measurement\Enums\UomDimension;
use Brick\Math\BigDecimal;
use Database\Factories\UomFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property UomDimension $dimension
 * @property bool $is_base
 * @property bool $requires_item_factor
 * @property string $factor_to_base
 * @property int $display_scale
 * @property bool $is_active
 */
class Uom extends Model
{
    /** @use HasFactory<UomFactory> */
    use HasFactory;

    use RecordsAuditTrail;

    protected $table = 'uoms';

    protected $fillable = [
        'code', 'name', 'dimension', 'is_base', 'requires_item_factor',
        'factor_to_base', 'display_scale', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'dimension' => UomDimension::class,
            'is_base' => 'boolean',
            'requires_item_factor' => 'boolean',
            'is_active' => 'boolean',
            'display_scale' => 'integer',
        ];
    }

    /**
     * The conversion factor as an exact decimal.
     *
     * Always read the factor through this accessor. The raw attribute is a
     * string precisely so that it never passes through a float on its way to
     * a calculation.
     */
    public function factorToBase(): BigDecimal
    {
        return BigDecimal::of($this->factor_to_base);
    }

    public function isSameDimensionAs(self $other): bool
    {
        return $this->dimension === $other->dimension;
    }

    /**
     * Whether this unit's size depends on the item it is measuring.
     *
     * A carton is the standard example: how much one holds is a fact about the
     * packaged product, so converting it without an item-specific factor would
     * be guesswork.
     */
    public function needsItemFactor(): bool
    {
        return $this->requires_item_factor;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOfDimension($query, UomDimension $dimension)
    {
        return $query->where('dimension', $dimension->value);
    }
}
