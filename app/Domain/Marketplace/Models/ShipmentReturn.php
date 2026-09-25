<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Inventory\Models\InventoryTransaction;
use App\Domain\Marketplace\Enums\ClaimStatus;
use App\Domain\Marketplace\Enums\ReturnKind;
use App\Domain\Warehousing\Models\Facility;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A parcel that came back: what it held, what was sellable, what was
 * damaged or missing, and the claim on the marketplace if one is owed.
 *
 * @property int $id
 * @property string $number
 * @property int $shipment_id
 * @property int $marketplace_id
 * @property int $brand_id
 * @property int $facility_id
 * @property ReturnKind $kind
 * @property string|null $return_awb
 * @property string|null $notes
 * @property bool $wrong_item
 * @property ClaimStatus $claim_status
 * @property Carbon|null $claim_deadline_at
 * @property string|null $claim_reference
 * @property string|null $claim_amount
 * @property string|null $claim_note
 * @property int|null $inventory_transaction_id
 * @property int|null $received_by
 * @property Carbon $received_at
 */
class ShipmentReturn extends Model
{
    use RecordsAuditTrail;

    protected $fillable = [
        'number', 'shipment_id', 'marketplace_id', 'brand_id', 'facility_id', 'kind', 'return_awb', 'notes',
        'wrong_item', 'claim_status', 'claim_deadline_at', 'claim_reference', 'claim_amount', 'claim_note',
        'inventory_transaction_id', 'received_by', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ReturnKind::class,
            'claim_status' => ClaimStatus::class,
            'wrong_item' => 'boolean',
            'claim_deadline_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function auditLabel(): string
    {
        return $this->number;
    }

    /** A claim still to raise whose deadline has gone. */
    public function claimOverdue(): bool
    {
        return $this->claim_status === ClaimStatus::Open
            && $this->claim_deadline_at !== null
            && $this->claim_deadline_at->isPast();
    }

    /**
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * @return BelongsTo<Marketplace, $this>
     */
    public function marketplace(): BelongsTo
    {
        return $this->belongsTo(Marketplace::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<Facility, $this>
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /**
     * @return BelongsTo<InventoryTransaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class, 'inventory_transaction_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * @return HasMany<ShipmentReturnLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ShipmentReturnLine::class)->orderBy('id');
    }
}
