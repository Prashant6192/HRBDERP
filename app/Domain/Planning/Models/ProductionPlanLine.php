<?php

declare(strict_types=1);

namespace App\Domain\Planning\Models;

use App\Domain\Inventory\Enums\StockAlertLevel;
use App\Domain\MasterData\Models\Item;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Planning\Enums\StoreKind;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One material a plan needs, with what the store had when it was checked.
 *
 * @property int $id
 * @property int $production_plan_id
 * @property int $line_no
 * @property StoreKind $store_kind
 * @property int $item_id
 * @property int $uom_id
 * @property string|null $percentage
 * @property bool $is_qs
 * @property bool $as_required
 * @property string $required_quantity
 * @property string $available_quantity
 * @property string $shortage_quantity
 * @property string $restock_quantity
 * @property StockAlertLevel $level_now
 * @property StockAlertLevel $level_after
 * @property list<string>|null $notes
 * @property Item $item
 * @property Uom $uom
 */
class ProductionPlanLine extends Model
{
    protected $fillable = [
        'production_plan_id', 'line_no', 'store_kind', 'item_id', 'uom_id', 'percentage', 'is_qs', 'as_required',
        'required_quantity', 'available_quantity', 'shortage_quantity', 'restock_quantity',
        'level_now', 'level_after', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'store_kind' => StoreKind::class,
            'line_no' => 'integer',
            'is_qs' => 'boolean',
            'as_required' => 'boolean',
            'level_now' => StockAlertLevel::class,
            'level_after' => StockAlertLevel::class,
            'notes' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ProductionPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProductionPlan::class, 'production_plan_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    /**
     * @return BelongsTo<Uom, $this>
     */
    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'uom_id');
    }

    public function shortage(): BigDecimal
    {
        return BigDecimal::of($this->shortage_quantity);
    }
}
