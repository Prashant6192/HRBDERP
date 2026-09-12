<?php

declare(strict_types=1);

namespace App\Domain\Manufacturing\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Formulation\Models\FormulaVersion;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Manufacturing\Enums\ManufacturingOrderStatus;
use App\Domain\MasterData\Models\Product;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Planning\Models\ProductionPlan;
use App\Domain\Warehousing\Models\Facility;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A batch being made.
 *
 * @property int $id
 * @property string $number
 * @property int|null $production_plan_id
 * @property int $formula_id
 * @property int $formula_version_id
 * @property int|null $product_id
 * @property string $planned_quantity
 * @property int $planned_uom_id
 * @property int|null $planned_units
 * @property ManufacturingOrderStatus $status
 * @property string|null $output_quantity
 * @property int|null $output_units
 * @property string|null $yield_percentage
 * @property int|null $output_lot_id
 * @property CarbonImmutable|null $manufactured_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property Formula $formula
 * @property FormulaVersion $formulaVersion
 * @property Product|null $product
 * @property Uom $plannedUom
 * @property ProductionPlan|null $plan
 */
class ManufacturingOrder extends Model
{
    use RecordsAuditTrail, SoftDeletes;

    protected $fillable = [
        'number', 'facility_id', 'production_plan_id', 'formula_id', 'formula_version_id', 'product_id',
        'planned_quantity', 'planned_uom_id', 'planned_units', 'status',
        'output_quantity', 'output_units', 'yield_percentage', 'output_lot_id', 'manufactured_at',
        'notes', 'created_by', 'approved_by', 'approved_at', 'started_by', 'started_at',
        'completed_by', 'completed_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ManufacturingOrderStatus::class,
            'planned_units' => 'integer',
            'output_units' => 'integer',
            'manufactured_at' => 'date',
            'approved_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * @return HasMany<ManufacturingOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ManufacturingOrderLine::class, 'manufacturing_order_id')->orderBy('store_kind')->orderBy('line_no');
    }

    /**
     * @return MorphMany<StockReservation, $this>
     */
    public function reservations(): MorphMany
    {
        return $this->morphMany(StockReservation::class, 'reservable');
    }

    /**
     * @return BelongsTo<InventoryLot, $this>
     */
    public function outputLot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'output_lot_id');
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function plannedQuantity(): BigDecimal
    {
        return BigDecimal::of($this->planned_quantity);
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
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ManufacturingOrderStatus::Draft->value,
            ManufacturingOrderStatus::Approved->value,
            ManufacturingOrderStatus::InProgress->value,
        ]);
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
                ->orWhereHas('product', fn (Builder $p) => $p->where('name', 'ilike', "%{$term}%"))
                ->orWhereHas('plan', fn (Builder $p) => $p->where('number', 'ilike', "%{$term}%"));
        });
    }
}
