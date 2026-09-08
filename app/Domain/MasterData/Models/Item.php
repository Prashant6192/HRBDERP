<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\MasterData\Enums\ItemType;
use App\Domain\Measurement\Models\Uom;
use App\Models\User;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A stockable thing: a raw material, a packaging material or a finished good.
 *
 * Subclasses (RawMaterial, PackagingMaterial, Product) narrow this to one type
 * so that each ERP module queries only its own items without repeating a where
 * clause at every call site.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property ItemType $type
 * @property string|null $density_g_per_ml
 * @property Uom $stockUom
 */
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    use RecordsAuditTrail, SoftDeletes;

    protected $table = 'items';

    protected $fillable = [
        'code', 'name', 'type', 'category_id', 'description',
        'stock_uom_id', 'purchase_uom_id', 'density_g_per_ml',
        'hsn_code', 'gst_rate', 'standard_cost',
        'brand', 'mrp', 'net_content', 'net_content_uom_id', 'barcode',
        'is_batch_tracked', 'requires_qc', 'shelf_life_days',
        'reorder_level', 'minimum_stock', 'maximum_stock', 'lead_time_days',
        'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => ItemType::class,
            'is_batch_tracked' => 'boolean',
            'requires_qc' => 'boolean',
            'is_active' => 'boolean',
            'shelf_life_days' => 'integer',
            'lead_time_days' => 'integer',
        ];
    }

    /**
     * Quantities and money stay as strings on the way out of the database.
     *
     * Casting them to float here would undo the point of storing them as
     * NUMERIC; callers that need arithmetic wrap them in BigDecimal.
     *
     * @var list<string>
     */
    protected array $auditExclude = ['updated_by'];

    public function auditLabel(): string
    {
        return "{$this->code} — {$this->name}";
    }

    // ---- Relations ---------------------------------------------------------

    /**
     * @return BelongsTo<ItemCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<Uom, $this>
     */
    public function stockUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'stock_uom_id');
    }

    /**
     * @return BelongsTo<Uom, $this>
     */
    public function purchaseUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'purchase_uom_id');
    }

    /**
     * @return BelongsTo<Uom, $this>
     */
    public function netContentUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'net_content_uom_id');
    }

    /**
     * @return HasMany<ItemUomConversion, $this>
     */
    public function uomConversions(): HasMany
    {
        return $this->hasMany(ItemUomConversion::class);
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
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ---- Scopes ------------------------------------------------------------

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
    public function scopeOfType(Builder $query, ItemType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    /**
     * Free-text search across the fields a user would actually type.
     *
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
                ->orWhere('barcode', 'ilike', "%{$term}%")
                ->orWhere('brand', 'ilike', "%{$term}%");
        });
    }
}
