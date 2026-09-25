<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Inventory\Models\InventoryTransaction;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Marketplace\Enums\PaymentMode;
use App\Domain\Marketplace\Enums\ShipmentStatus;
use App\Domain\Marketplace\Enums\StockState;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * One parcel, from its label to the courier's hands.
 *
 * The label is the marketplace's; the parcel is ours to pack. Stock is held
 * for it from the moment the label is uploaded, and leaves through the
 * ledger when someone scans the label at the packing table — so a label
 * printed and never packed shows up as exactly that.
 *
 * @property int $id
 * @property int $label_batch_id
 * @property int $label_file_id
 * @property int $marketplace_id
 * @property int $brand_id
 * @property int $warehouse_id
 * @property list<int> $pages
 * @property string|null $awb
 * @property string|null $alt_code
 * @property string|null $order_number
 * @property string|null $courier
 * @property PaymentMode $payment_mode
 * @property string|null $payable_amount
 * @property string|null $invoice_number
 * @property string|null $customer_name
 * @property string|null $customer_state
 * @property string|null $seller_gstin
 * @property ShipmentStatus $status
 * @property StockState $stock_state
 * @property int $print_count
 * @property string|null $pack_method
 * @property list<string>|null $warnings
 * @property array<string, mixed>|null $extraction
 * @property Carbon|null $printed_at
 * @property Carbon|null $packed_at
 * @property Carbon|null $handed_over_at
 * @property Carbon|null $returned_at
 */
class Shipment extends Model
{
    use RecordsAuditTrail;

    protected $fillable = [
        'label_batch_id', 'label_file_id', 'marketplace_id', 'brand_id', 'warehouse_id', 'pages',
        'awb', 'alt_code', 'order_number', 'courier', 'payment_mode', 'payable_amount',
        'invoice_number', 'invoice_date', 'customer_name', 'customer_state', 'seller_gstin',
        'status', 'stock_state',
        'printed_at', 'printed_by', 'print_count', 'packed_at', 'packed_by', 'pack_method', 'pack_note',
        'handed_over_at', 'handed_over_by', 'handover_sheet_id', 'cancelled_at', 'cancelled_by', 'cancel_reason', 'returned_at',
        'extraction', 'warnings',
    ];

    /** What the reader made of the page is kept, not diffed. */
    protected array $auditExclude = ['extraction'];

    protected function casts(): array
    {
        return [
            'pages' => 'array',
            'payment_mode' => PaymentMode::class,
            'status' => ShipmentStatus::class,
            'stock_state' => StockState::class,
            'invoice_date' => 'date',
            'printed_at' => 'datetime',
            'print_count' => 'integer',
            'packed_at' => 'datetime',
            'handed_over_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'returned_at' => 'datetime',
            'extraction' => 'array',
            'warnings' => 'array',
        ];
    }

    public function auditLabel(): string
    {
        return $this->awb ?? $this->order_number ?? "Parcel #{$this->id}";
    }

    /**
     * How a person refers to this parcel.
     */
    public function reference(): string
    {
        return $this->awb ?? $this->order_number ?? "Parcel #{$this->id}";
    }

    /**
     * @return BelongsTo<LabelBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(LabelBatch::class, 'label_batch_id');
    }

    /**
     * @return BelongsTo<LabelFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(LabelFile::class, 'label_file_id');
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
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return HasMany<ShipmentLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ShipmentLine::class)->orderBy('line_no');
    }

    /**
     * What to take off the shelf for this parcel, product by product.
     *
     * @return HasMany<ShipmentPick, $this>
     */
    public function picks(): HasMany
    {
        return $this->hasMany(ShipmentPick::class)->orderBy('id');
    }

    /**
     * @return HasOne<ShipmentReturn, $this>
     */
    public function shipmentReturn(): HasOne
    {
        return $this->hasOne(ShipmentReturn::class);
    }

    /**
     * @return BelongsTo<HandoverSheet, $this>
     */
    public function handoverSheet(): BelongsTo
    {
        return $this->belongsTo(HandoverSheet::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function packer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'packed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function printer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by');
    }

    /**
     * @return MorphMany<StockReservation, $this>
     */
    public function reservations(): MorphMany
    {
        return $this->morphMany(StockReservation::class, 'reservable');
    }

    /**
     * @return MorphMany<InventoryTransaction, $this>
     */
    public function transactions(): MorphMany
    {
        return $this->morphMany(InventoryTransaction::class, 'reference');
    }

    /**
     * Every line of the label is matched to a listing, so the parcel has
     * a pick list to pack from.
     */
    public function isMapped(): bool
    {
        $this->loadMissing(['lines', 'picks']);

        return $this->lines->isNotEmpty()
            && $this->lines->every(fn (ShipmentLine $l) => $l->listing_id !== null)
            && $this->picks->isNotEmpty();
    }

    /**
     * A scanned code, whichever barcode on the label it came from.
     *
     * @param  Builder<Shipment>  $query
     * @return Builder<Shipment>
     */
    public function scopeMatchingCode(Builder $query, string $code): Builder
    {
        $code = strtoupper(trim($code));

        return $query->where(fn (Builder $q) => $q
            ->whereRaw('upper(awb) = ?', [$code])
            ->orWhereRaw('upper(alt_code) = ?', [$code])
            ->orWhereRaw('upper(order_number) = ?', [$code]));
    }

    /**
     * @param  Builder<Shipment>  $query
     * @return Builder<Shipment>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('awb', 'ilike', "%{$term}%")
            ->orWhere('alt_code', 'ilike', "%{$term}%")
            ->orWhere('order_number', 'ilike', "%{$term}%")
            ->orWhere('invoice_number', 'ilike', "%{$term}%")
            ->orWhere('customer_name', 'ilike', "%{$term}%")
            ->orWhereHas('lines', fn (Builder $l) => $l->where('seller_sku', 'ilike', "%{$term}%")));
    }
}
