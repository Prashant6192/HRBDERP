<?php

declare(strict_types=1);

namespace App\Domain\Formulation\Models;

use App\Domain\MasterData\Models\Item;
use Brick\Math\BigDecimal;
use Database\Factories\FormulaIngredientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a recipe: a material and how much of the batch it is.
 *
 * Deliberately NOT audited through RecordsAuditTrail. The general audit log
 * is readable by roles that may not see formulations, and an old/new value
 * diff of this row would be the recipe. Changes are recorded as "version
 * edited" events on the version and in the formula access trail instead.
 *
 * @property int $id
 * @property int $formula_version_id
 * @property int $line_no
 * @property int $item_id
 * @property string|null $inci_name
 * @property string|null $percentage
 * @property bool $is_qs
 * @property string|null $qs_note
 * @property string|null $grade
 * @property string|null $phase
 * @property string|null $purpose
 * @property Item $item
 */
class FormulaIngredient extends Model
{
    /** @use HasFactory<FormulaIngredientFactory> */
    use HasFactory;

    protected $fillable = [
        'formula_version_id', 'line_no', 'item_id', 'inci_name', 'percentage',
        'is_qs', 'qs_note', 'grade', 'phase', 'purpose', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'is_qs' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<FormulaVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(FormulaVersion::class, 'formula_version_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function hasFixedPercentage(): bool
    {
        return $this->percentage !== null;
    }

    /**
     * "As required": no percentage and not the filler — dosed at the kettle.
     */
    public function isAsRequired(): bool
    {
        return $this->percentage === null && ! $this->is_qs;
    }

    public function percentage(): ?BigDecimal
    {
        return $this->percentage === null ? null : BigDecimal::of($this->percentage);
    }
}
