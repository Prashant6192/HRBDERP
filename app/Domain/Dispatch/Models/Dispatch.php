<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Dispatch\Enums\DispatchAttachmentKind;
use App\Domain\Dispatch\Enums\DispatchStatus;
use App\Domain\Inventory\Models\InventoryTransaction;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One consignment of finished goods leaving a facility.
 *
 * The document records the intent, the invoice and the e-invoice
 * particulars; the stock itself leaves only through the ledger posting
 * DispatchService makes when the goods are dispatched, so what left can
 * always be traced to who sent it and under which invoice.
 *
 * @property int $id
 * @property string $number
 * @property int $facility_id
 * @property int $warehouse_id
 * @property int $customer_id
 * @property DispatchStatus $status
 * @property string|null $invoice_number
 * @property string|null $irn
 * @property string|null $ack_number
 * @property bool $is_interstate
 */
class Dispatch extends Model
{
    use RecordsAuditTrail, SoftDeletes;

    protected $fillable = [
        'number', 'facility_id', 'warehouse_id', 'customer_id', 'status', 'reference',
        'invoice_number', 'invoice_date', 'place_of_supply', 'is_interstate',
        'ship_to_name', 'ship_to_gstin', 'ship_to_address_line_1', 'ship_to_address_line_2', 'ship_to_city', 'ship_to_state', 'ship_to_pincode',
        'irn', 'ack_number', 'ack_date', 'signed_qr',
        'transporter_name', 'transporter_gstin', 'vehicle_number', 'lr_number', 'lr_date', 'eway_bill_number', 'eway_bill_date', 'distance_km',
        'taxable_value', 'cgst', 'sgst', 'igst', 'other_charges', 'round_off', 'total_value',
        'notes', 'created_by', 'invoiced_by', 'invoiced_at', 'dispatched_by', 'dispatched_at',
        'delivered_by', 'delivered_at', 'delivery_note', 'cancelled_at', 'cancel_reason',
    ];

    /** The signed QR is long and of no use in an audit diff. */
    protected array $auditExclude = ['signed_qr'];

    protected function casts(): array
    {
        return [
            'status' => DispatchStatus::class,
            'invoice_date' => 'date',
            'is_interstate' => 'boolean',
            'ack_date' => 'datetime',
            'lr_date' => 'date',
            'eway_bill_date' => 'date',
            'distance_km' => 'integer',
            'invoiced_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function auditLabel(): string
    {
        return $this->number;
    }

    /**
     * @return HasMany<DispatchLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(DispatchLine::class)->orderBy('line_no');
    }

    /**
     * @return HasMany<DispatchAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(DispatchAttachment::class)->orderBy('id');
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
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invoicer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invoiced_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function dispatcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function deliverer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    /**
     * @return MorphMany<InventoryTransaction, $this>
     */
    public function transactions(): MorphMany
    {
        return $this->morphMany(InventoryTransaction::class, 'reference');
    }

    public function hasIrn(): bool
    {
        return $this->irn !== null && $this->irn !== '' && $this->ack_number !== null && $this->ack_number !== '';
    }

    public function hasAttachment(DispatchAttachmentKind $kind): bool
    {
        return $this->attachments->contains(fn (DispatchAttachment $a) => $a->kind === $kind);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $query) use ($term): void {
            $query->where('number', 'ilike', "%{$term}%")
                ->orWhere('invoice_number', 'ilike', "%{$term}%")
                ->orWhere('irn', 'ilike', "%{$term}%")
                ->orWhere('reference', 'ilike', "%{$term}%")
                ->orWhere('vehicle_number', 'ilike', "%{$term}%")
                ->orWhere('eway_bill_number', 'ilike', "%{$term}%")
                ->orWhereHas('customer', fn (Builder $q) => $q->where('name', 'ilike', "%{$term}%"));
        });
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [DispatchStatus::Draft->value, DispatchStatus::Invoiced->value, DispatchStatus::Dispatched->value]);
    }
}
