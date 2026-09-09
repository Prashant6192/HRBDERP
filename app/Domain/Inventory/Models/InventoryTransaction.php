<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * One movement of stock, with its lines. Append-only.
 *
 * @property int $id
 * @property string $number
 * @property InventoryTransactionType $type
 */
class InventoryTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'number', 'type', 'warehouse_id', 'counterpart_warehouse_id',
        'reference_type', 'reference_id', 'transacted_at', 'reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => InventoryTransactionType::class,
            'transacted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new RuntimeException('Inventory transactions are immutable. Post a correcting transaction instead.');
        });

        static::deleting(static function (): never {
            throw new RuntimeException('Inventory transactions are immutable and cannot be deleted.');
        });
    }

    /**
     * @return HasMany<InventoryTransactionLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InventoryTransactionLine::class, 'inventory_transaction_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function counterpartWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'counterpart_warehouse_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
