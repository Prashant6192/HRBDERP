<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A courier's pickup: the parcels handed over together, for the courier's
 * person to count and sign for.
 *
 * @property int $id
 * @property string $number
 * @property int $facility_id
 * @property int $warehouse_id
 * @property string $courier
 * @property int $shipment_count
 * @property string|null $received_by_name
 * @property Carbon $handed_over_at
 */
class HandoverSheet extends Model
{
    protected $fillable = [
        'number', 'facility_id', 'warehouse_id', 'courier', 'shipment_count', 'received_by_name', 'handed_over_by', 'handed_over_at',
    ];

    protected function casts(): array
    {
        return ['handed_over_at' => 'datetime', 'shipment_count' => 'integer'];
    }

    /**
     * @return HasMany<Shipment, $this>
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<Facility, $this>
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function handedOverBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handed_over_by');
    }
}
