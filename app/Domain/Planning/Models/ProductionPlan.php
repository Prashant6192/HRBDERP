<?php

declare(strict_types=1);

namespace App\Domain\Planning\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Formulation\Models\FormulaVersion;
use App\Domain\MasterData\Models\Product;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Planning\Enums\ProductionPlanStatus;
use App\Domain\Warehousing\Models\Facility;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An intention to make a quantity of a product from a formula.
 *
 * @property int $id
 * @property string $number
 * @property int $formula_id
 * @property int $formula_version_id
 * @property int|null $product_id
 * @property string $planned_quantity
 * @property int $planned_uom_id
 * @property int|null $planned_units
 * @property ProductionPlanStatus $status
 * @property CarbonImmutable|null $planned_start_date
 * @property list<string>|null $warnings
 * @property CarbonImmutable|null $checked_at
 * @property Formula $formula
 * @property FormulaVersion $formulaVersion
 * @property Uom $plannedUom
 */
class ProductionPlan extends Model
{
    use RecordsAuditTrail, SoftDeletes;

    protected $fillable = [
        'number', 'facility_id', 'formula_id', 'formula_version_id', 'product_id', 'planned_quantity', 'planned_uom_id',
        'planned_units', 'status', 'planned_start_date', 'notes', 'warnings', 'created_by',
        'checked_at', 'requested_at', 'cancelled_at',
    ];

    /** @var list<string> */
    protected array $auditExclude = ['warnings', 'planned_units'];

    protected function casts(): array
    {
        return [
            'status' => ProductionPlanStatus::class,
            'planned_start_date' => 'date',
            'planned_units' => 'integer',
            'warnings' => 'array',
            'checked_at' => 'datetime',
            'requested_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function auditLabel(): string
    {
        return $this->number;
    }

    /**
     * @return BelongsTo<Formula, $this>
     */
    public function formula(): BelongsTo
    {
        return $this->belongsTo(Formula::class, 'formula_id');
    }

    /**
     * @return BelongsTo<FormulaVersion, $this>
     */
    public function formulaVersion(): BelongsTo
    {
        return $this->belongsTo(FormulaVersion::class, 'formula_version_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * @return BelongsTo<Uom, $this>
     */
    public function plannedUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'planned_uom_id');
    }

    /**
     * @return HasMany<ProductionPlanLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ProductionPlanLine::class, 'production_plan_id')->orderBy('store_kind')->orderBy('line_no');
    }

    /**
     * @return HasMany<MaterialRequest, $this>
     */
    public function materialRequests(): HasMany
    {
        return $this->hasMany(MaterialRequest::class, 'production_plan_id')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function plannedQuantity(): BigDecimal
    {
        return BigDecimal::of($this->planned_quantity);
    }

    public function hasShortage(): bool
    {
        return $this->lines->contains(fn (ProductionPlanLine $line) => $line->shortage()->isPositive());
    }

    /**
     * @return BelongsTo<Facility, $this>
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
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
                ->orWhereHas('formula', fn (Builder $f) => $f->where('name', 'ilike', "%{$term}%")->orWhere('code', 'ilike', "%{$term}%"))
                ->orWhereHas('product', fn (Builder $p) => $p->where('name', 'ilike', "%{$term}%"));
        });
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ProductionPlanStatus::Draft->value,
            ProductionPlanStatus::Checked->value,
            ProductionPlanStatus::Requested->value,
            ProductionPlanStatus::InProduction->value,
        ]);
    }
}
