<?php

declare(strict_types=1);

namespace App\Domain\Formulation\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Formulation\Enums\FormulaStatus;
use App\Domain\MasterData\Models\Product;
use App\Models\User;
use Database\Factories\FormulaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A product's recipe, as an identity that outlives any one version of it.
 *
 * The formula row holds nothing secret — a name, a code, a product, a
 * status — so it may be listed to anyone with formula.view. The recipe
 * itself lives in FormulaIngredient rows under a FormulaVersion, and those
 * are only ever loaded behind the second-factor unlock.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property FormulaStatus $status
 * @property int|null $product_id
 * @property int|null $active_version_id
 * @property FormulaVersion|null $activeVersion
 */
class Formula extends Model
{
    /** @use HasFactory<FormulaFactory> */
    use HasFactory;

    use RecordsAuditTrail, SoftDeletes;

    protected $fillable = [
        'code', 'name', 'product_id', 'status', 'active_version_id',
        'description', 'created_by', 'updated_by',
    ];

    /** @var list<string> */
    protected array $auditExclude = ['updated_by', 'active_version_id'];

    protected function casts(): array
    {
        return [
            'status' => FormulaStatus::class,
        ];
    }

    public function auditLabel(): string
    {
        return "{$this->code} {$this->name}";
    }

    /**
     * @return HasMany<FormulaVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(FormulaVersion::class, 'formula_id')->orderByDesc('version_number');
    }

    /**
     * @return BelongsTo<FormulaVersion, $this>
     */
    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(FormulaVersion::class, 'active_version_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The open draft, if one exists. A formula has at most one at a time.
     */
    public function draftVersion(): ?FormulaVersion
    {
        return $this->versions()->where('status', 'draft')->first();
    }

    public function isArchived(): bool
    {
        return $this->status === FormulaStatus::Archived;
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
            $q->where('code', 'ilike', "%{$term}%")
                ->orWhere('name', 'ilike', "%{$term}%")
                ->orWhereHas('product', fn (Builder $p) => $p->where('name', 'ilike', "%{$term}%")->orWhere('code', 'ilike', "%{$term}%"));
        });
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', FormulaStatus::Active->value);
    }
}
