<?php

declare(strict_types=1);

namespace App\Domain\Warehousing\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A named place inside a warehouse — a rack, bin, floor area or cold room.
 *
 * @property int $warehouse_id
 * @property string $code
 */
class WarehouseLocation extends Model
{
    use RecordsAuditTrail;

    protected $fillable = ['warehouse_id', 'code', 'name', 'type', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function auditLabel(): string
    {
        return "{$this->code} — {$this->name}";
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
