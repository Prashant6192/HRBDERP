<?php

declare(strict_types=1);

namespace App\Domain\Formulation\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Formulation\Enums\FormulaVersionStatus;
use App\Domain\Measurement\Models\Uom;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Database\Factories\FormulaVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One concrete recipe for a formula.
 *
 * Versions are never edited once active: a change is a new draft version
 * that, on activation, supersedes the previous one. Production orders
 * reference the version they were made from, so history stays honest.
 *
 * @property int $id
 * @property int $formula_id
 * @property int $version_number
 * @property FormulaVersionStatus $status
 * @property string $batch_size
 * @property int $batch_uom_id
 * @property string $total_percentage
 * @property CarbonImmutable|null $activated_at
 * @property Formula $formula
 * @property Uom $batchUom
 */
class FormulaVersion extends Model
{
    /** @use HasFactory<FormulaVersionFactory> */
    use HasFactory;

    use RecordsAuditTrail;

    protected $fillable = [
        'formula_id', 'version_number', 'status', 'batch_size', 'batch_uom_id',
        'total_percentage', 'notes', 'change_summary', 'source', 'source_reference',
        'created_by', 'approved_by', 'approved_at', 'activated_at', 'superseded_at',
    ];

    /**
     * Nothing about the recipe itself is written to the general audit log;
     * it records that a version changed, not what it contains.
     *
     * @var list<string>
     */
    protected array $auditExclude = ['notes', 'total_percentage', 'batch_size'];

    protected function casts(): array
    {
        return [
            'status' => FormulaVersionStatus::class,
            'version_number' => 'integer',
            'approved_at' => 'datetime',
            'activated_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function auditLabel(): string
    {
        $formula = $this->relationLoaded('formula') ? $this->formula : $this->formula()->first();

        return ($formula?->code ?? 'formula')." v{$this->version_number}";
    }

    /**
     * @return BelongsTo<Formula, $this>
     */
    public function formula(): BelongsTo
    {
        return $this->belongsTo(Formula::class, 'formula_id');
    }

    /**
     * @return HasMany<FormulaIngredient, $this>
     */
    public function ingredients(): HasMany
    {
        return $this->hasMany(FormulaIngredient::class, 'formula_version_id')->orderBy('line_no');
    }

    /**
     * @return BelongsTo<Uom, $this>
     */
    public function batchUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'batch_uom_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function isActive(): bool
    {
        return $this->status === FormulaVersionStatus::Active;
    }

    public function batchSize(): BigDecimal
    {
        return BigDecimal::of($this->batch_size);
    }

    public function totalPercentage(): BigDecimal
    {
        return BigDecimal::of($this->total_percentage);
    }

    public function hasQsLine(): bool
    {
        return $this->ingredients->contains(fn (FormulaIngredient $line) => $line->is_qs);
    }

    /**
     * Whether the recipe accounts for the whole batch: fixed percentages
     * sum to 100, or a QS line takes up the remainder.
     */
    public function isComplete(): bool
    {
        return $this->hasQsLine() || $this->totalPercentage()->isEqualTo(100);
    }
}
