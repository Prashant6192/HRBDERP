<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\MasterData\Enums\ItemType;
use Database\Factories\ItemCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property ItemType|null $item_type
 */
class ItemCategory extends Model
{
    /** @use HasFactory<ItemCategoryFactory> */
    use HasFactory;

    use RecordsAuditTrail;

    protected $fillable = [
        'code', 'name', 'item_type', 'parent_id', 'description', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'item_type' => ItemType::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'category_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
