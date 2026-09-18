<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\Services;

use App\Domain\Dispatch\Enums\DispatchAttachmentKind;
use App\Domain\Dispatch\Enums\DispatchStatus;
use App\Domain\Dispatch\Exceptions\DispatchException;
use App\Domain\Dispatch\Models\Customer;
use App\Domain\Dispatch\Models\Dispatch;
use App\Domain\Dispatch\Models\DispatchAttachment;
use App\Domain\Dispatch\Models\DispatchLine;
use App\Domain\Dispatch\Support\GstStateCodes;
use App\Domain\Inventory\DTOs\LedgerLine;
use App\Domain\Inventory\DTOs\LedgerPosting;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Inventory\Services\SequenceService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\Item;
use App\Domain\Measurement\Exceptions\IncompatibleUnitsException;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Measurement\Services\UnitConversionService;
use App\Domain\Warehousing\Enums\FacilityCapability;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Warehouse;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Finished goods leaving the factory.
 *
 *   draft → invoiced → dispatched → delivered      (or cancelled)
 *
 * Only a finished goods store can dispatch, only batches QC has released,
 * and a client's batches only to that client. The invoice is raised in the
 * billing software and recorded here with what the IRP returned; goods do
 * not leave until the e-invoice is on record, and when they do the stock
 * goes out through the ledger, batch by batch, against the consignment.
 */
class DispatchService
{
    public const string DISK = 'local';

    private const int MONEY_SCALE = 2;

    public function __construct(
        private readonly SequenceService $sequences,
        private readonly InventoryLedgerService $ledger,
        private readonly StockBalanceService $balances,
        private readonly UnitConversionService $conversions,
        private readonly EInvoiceService $einvoice,
    ) {}

    /**
     * Write a consignment up. Nothing moves yet.
     *
     * @param  array{warehouse_id: int, customer_id: int, reference?: string|null, place_of_supply?: string|null, notes?: string|null, other_charges?: string|null, ship_to?: array<string, string|null>|null}  $attributes
     * @param  list<array{item_id: int, lot_id: int, quantity: string, unit_price: string, uom_id?: int|null, discount_percent?: string|null, gst_rate?: string|null, hsn_code?: string|null, description?: string|null}>  $lines
     */
    public function create(array $attributes, array $lines, ?int $userId): Dispatch
    {
        if ($lines === []) {
            throw new DispatchException('Add at least one batch to the dispatch.');
        }

        return DB::transaction(function () use ($attributes, $lines, $userId): Dispatch {
            $store = Warehouse::query()->with('facility')->findOrFail($attributes['warehouse_id']);
            $this->assertDispatchStore($store);

            $customer = Customer::query()->findOrFail($attributes['customer_id']);

            if (! $customer->is_active) {
                throw new DispatchException("{$customer->name} is inactive; reactivate the customer before dispatching to them.");
            }

            $seller = $this->einvoice->seller($store->facility);

            if ($seller['state_code'] === null) {
                throw new DispatchException("Set the GSTIN on {$store->facility->name} (or the company's GSTIN in settings) first: the invoice needs the seller's state.");
            }

            $pos = $customer->stateCode() ?? $this->stateCode($attributes['place_of_supply'] ?? null);

            if ($pos === null) {
                throw new DispatchException("{$customer->name} has no GSTIN on file, so give the place of supply (the state the goods are delivered in).");
            }

            $interstate = $pos !== $seller['state_code'];
            $shipTo = $attributes['ship_to'] ?? null;

            $dispatch = Dispatch::create([
                'number' => $this->sequences->nextNumber('DSP', now()->format('ym')),
                'facility_id' => $store->facility_id,
                'warehouse_id' => $store->id,
                'customer_id' => $customer->id,
                'status' => DispatchStatus::Draft,
                'reference' => $attributes['reference'] ?? null,
                'place_of_supply' => $pos,
                'is_interstate' => $interstate,
                'ship_to_name' => $shipTo['name'] ?? null,
                'ship_to_gstin' => isset($shipTo['gstin']) && $shipTo['gstin'] !== '' ? strtoupper($shipTo['gstin']) : null,
                'ship_to_address_line_1' => $shipTo['address_line_1'] ?? null,
                'ship_to_address_line_2' => $shipTo['address_line_2'] ?? null,
                'ship_to_city' => $shipTo['city'] ?? null,
                'ship_to_state' => $shipTo['state'] ?? null,
                'ship_to_pincode' => $shipTo['pincode'] ?? null,
                'other_charges' => $this->money($attributes['other_charges'] ?? '0')->__toString(),
                'notes' => $attributes['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $lineNo = 0;

            foreach ($lines as $line) {
                $this->addLine($dispatch, $store, $customer, $line, ++$lineNo, $interstate);
            }

            $this->total($dispatch);

            return $dispatch->refresh()->load(['lines.item', 'lines.lot', 'customer', 'facility', 'warehouse']);
        });
    }

    /**
     * Record the invoice the billing software raised, and what the IRP
     * returned for it.
     *
     * @param  array{invoice_number: string, invoice_date: string, irn?: string|null, ack_number?: string|null, ack_date?: string|null, signed_qr?: string|null}  $data
     */
    public function recordInvoice(Dispatch $dispatch, array $data, ?int $userId): Dispatch
    {
        return DB::transaction(function () use ($dispatch, $data, $userId): Dispatch {
            $dispatch = $this->lock($dispatch);

            if (! $dispatch->status->isEditable()) {
                throw new DispatchException("{$dispatch->number} is {$dispatch->status->label()}; the invoice can no longer be changed.");
            }

            $irn = isset($data['irn']) && trim((string) $data['irn']) !== '' ? strtolower(trim((string) $data['irn'])) : null;

            if ($irn !== null && ! EInvoiceService::looksLikeIrn($irn)) {
                throw new DispatchException('That is not an IRN: the IRP returns a 64-character reference.');
            }

            if ($irn !== null && (empty($data['ack_number']) || empty($data['ack_date']))) {
                throw new DispatchException('Record the acknowledgement number and date that came with the IRN.');
            }

            $dispatch->fill([
                'invoice_number' => trim((string) $data['invoice_number']),
                'invoice_date' => $data['invoice_date'],
                'irn' => $irn,
                'ack_number' => $irn === null ? null : trim((string) $data['ack_number']),
                'ack_date' => $irn === null ? null : $data['ack_date'],
                'signed_qr' => $irn === null ? null : ($data['signed_qr'] ?? null),
                'status' => DispatchStatus::Invoiced,
                'invoiced_by' => $dispatch->invoiced_by ?? $userId,
                'invoiced_at' => $dispatch->invoiced_at ?? now(),
            ])->save();

            return $dispatch->refresh();
        });
    }

    /**
     * The goods leave. The stock goes out through the ledger, one line per
     * batch, against the consignment.
     *
     * @param  array{transporter_name?: string|null, transporter_gstin?: string|null, vehicle_number?: string|null, lr_number?: string|null, lr_date?: string|null, eway_bill_number?: string|null, eway_bill_date?: string|null, distance_km?: int|null}  $transport
     */
    public function dispatchGoods(Dispatch $dispatch, array $transport, ?int $userId): Dispatch
    {
        return DB::transaction(function () use ($dispatch, $transport, $userId): Dispatch {
            $dispatch = $this->lock($dispatch);
            $dispatch->load(['lines.item.stockUom', 'lines.lot', 'attachments', 'customer', 'warehouse']);

            if ($dispatch->status !== DispatchStatus::Invoiced) {
                throw new DispatchException(match ($dispatch->status) {
                    DispatchStatus::Draft => "{$dispatch->number} has no invoice recorded yet. Record the invoice first.",
                    default => "{$dispatch->number} is {$dispatch->status->label()}.",
                });
            }

            $missing = $this->readiness($dispatch)['missing'];

            if ($missing !== []) {
                throw new DispatchException('Not ready to leave: '.implode('; ', $missing).'.');
            }

            $store = $dispatch->warehouse;
            $ledgerLines = [];

            foreach ($dispatch->lines as $line) {
                /** @var DispatchLine $line */
                $available = $this->availableFor($line->item, $line->lot, $store);
                $quantity = BigDecimal::of($line->quantity);

                if ($available->isLessThan($quantity)) {
                    throw new DispatchException(sprintf(
                        'Batch %s of %s: only %s %s is free in %s now (needed %s). Something else took it since the note was written.',
                        $line->lot->batch_number,
                        $line->item->name,
                        $available->strippedOfTrailingZeros(),
                        $line->item->stockUom?->code,
                        $store->name,
                        $quantity->strippedOfTrailingZeros(),
                    ));
                }

                $ledgerLines[] = new LedgerLine($line->item_id, $store->id, $quantity->negated(), $line->lot_id);
            }

            $this->ledger->post(new LedgerPosting(
                type: InventoryTransactionType::SalesDispatch,
                warehouseId: $store->id,
                lines: $ledgerLines,
                reference: $dispatch,
                reason: "Dispatched on {$dispatch->number} to {$dispatch->customer->name}, invoice {$dispatch->invoice_number}",
                createdBy: $userId,
            ));

            $dispatch->fill([
                'transporter_name' => $transport['transporter_name'] ?? $dispatch->transporter_name,
                'transporter_gstin' => isset($transport['transporter_gstin']) && $transport['transporter_gstin'] !== '' ? strtoupper($transport['transporter_gstin']) : $dispatch->transporter_gstin,
                'vehicle_number' => isset($transport['vehicle_number']) && $transport['vehicle_number'] !== '' ? strtoupper($transport['vehicle_number']) : $dispatch->vehicle_number,
                'lr_number' => $transport['lr_number'] ?? $dispatch->lr_number,
                'lr_date' => $transport['lr_date'] ?? $dispatch->lr_date,
                'eway_bill_number' => $transport['eway_bill_number'] ?? $dispatch->eway_bill_number,
                'eway_bill_date' => $transport['eway_bill_date'] ?? $dispatch->eway_bill_date,
                'distance_km' => $transport['distance_km'] ?? $dispatch->distance_km,
                'status' => DispatchStatus::Dispatched,
                'dispatched_by' => $userId,
                'dispatched_at' => now(),
            ])->save();

            return $dispatch->refresh();
        });
    }

    public function deliver(Dispatch $dispatch, ?int $userId, ?string $note = null): Dispatch
    {
        return DB::transaction(function () use ($dispatch, $userId, $note): Dispatch {
            $dispatch = $this->lock($dispatch);

            if ($dispatch->status !== DispatchStatus::Dispatched) {
                throw new DispatchException("{$dispatch->number} is {$dispatch->status->label()}; only a dispatched consignment can be marked delivered.");
            }

            $dispatch->fill([
                'status' => DispatchStatus::Delivered,
                'delivered_by' => $userId,
                'delivered_at' => now(),
                'delivery_note' => $note,
            ])->save();

            return $dispatch->refresh();
        });
    }

    public function cancel(Dispatch $dispatch, ?string $reason = null): Dispatch
    {
        return DB::transaction(function () use ($dispatch, $reason): Dispatch {
            $dispatch = $this->lock($dispatch);

            if (! $dispatch->status->isEditable()) {
                throw new DispatchException("{$dispatch->number} is {$dispatch->status->label()} and cannot be cancelled: the goods have left. Book a return instead.");
            }

            $dispatch->fill([
                'status' => DispatchStatus::Cancelled,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ])->save();

            return $dispatch->refresh();
        });
    }

    /**
     * Keep a paper with the consignment.
     */
    public function attach(Dispatch $dispatch, UploadedFile $file, DispatchAttachmentKind $kind, ?int $userId): DispatchAttachment
    {
        if ($dispatch->status === DispatchStatus::Cancelled) {
            throw new DispatchException("{$dispatch->number} is cancelled.");
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $path = sprintf('dispatches/%d/%s-%s.%s', $dispatch->id, $kind->value, Str::lower(Str::random(10)), $extension);

        Storage::disk(self::DISK)->put($path, (string) file_get_contents($file->getRealPath()));

        return $dispatch->attachments()->create([
            'kind' => $kind,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize() ?: 0,
            'uploaded_by' => $userId,
        ]);
    }

    /**
     * Whether an IRN is required before this consignment leaves: e-invoicing
     * is mandatory here and the buyer is GST-registered. An unregistered
     * buyer (B2C) is invoiced without an IRN.
     */
    public function requiresIrn(Dispatch $dispatch): bool
    {
        return (bool) config('erp.dispatch.einvoice_mandatory', true) && $dispatch->customer->isRegistered();
    }

    /**
     * What still stands between this consignment and the gate.
     *
     * @return array{ready: bool, requires_irn: bool, missing: list<string>}
     */
    public function readiness(Dispatch $dispatch): array
    {
        $dispatch->loadMissing(['customer', 'attachments']);
        $requiresIrn = $this->requiresIrn($dispatch);
        $missing = [];

        if ($dispatch->invoice_number === null || $dispatch->invoice_date === null) {
            $missing[] = 'the invoice number and date';
        }

        if ($requiresIrn && ! $dispatch->hasIrn()) {
            $missing[] = 'the IRN and acknowledgement from the IRP (e-invoicing is mandatory for a GST-registered buyer)';
        }

        if ($requiresIrn && ! $dispatch->hasAttachment(DispatchAttachmentKind::SignedInvoice)) {
            $missing[] = 'the signed e-invoice (with the QR) uploaded';
        }

        if (! $requiresIrn && ! $dispatch->hasAttachment(DispatchAttachmentKind::Invoice) && ! $dispatch->hasAttachment(DispatchAttachmentKind::SignedInvoice)) {
            $missing[] = 'the invoice uploaded';
        }

        return ['ready' => $missing === [], 'requires_irn' => $requiresIrn, 'missing' => $missing];
    }

    /**
     * The batches of an item a finished goods store can dispatch right now,
     * with whose they are.
     *
     * @return Collection<int, array{lot_id: int, batch: string, expiry_at: string|null, available: string, owner_client_id: int|null, owner: string|null}>
     */
    public function lotsFor(Item $item, Warehouse $store): Collection
    {
        return $this->balances->releasableBalances($item, [$store->id], anyOwner: true)
            ->filter(fn (StockBalance $b) => $b->lot_id !== null && $b->available()->isPositive())
            ->map(function (StockBalance $b): array {
                /** @var InventoryLot $lot */
                $lot = $b->getRelation('lot');
                $lot->loadMissing('ownerClient:id,code,name');

                return [
                    'lot_id' => (int) $b->lot_id,
                    'batch' => (string) $lot->batch_number,
                    'expiry_at' => $lot->expiry_at?->toDateString(),
                    'available' => (string) $b->available(),
                    'owner_client_id' => $lot->owner_client_id,
                    'owner' => $lot->ownerClient?->name ?? config('erp.company.brand'),
                ];
            })
            ->values();
    }

    // ---- Internals ---------------------------------------------------------

    /**
     * @param  array{item_id: int, lot_id: int, quantity: string, unit_price: string, uom_id?: int|null, discount_percent?: string|null, gst_rate?: string|null, hsn_code?: string|null, description?: string|null}  $line
     */
    private function addLine(Dispatch $dispatch, Warehouse $store, Customer $customer, array $line, int $lineNo, bool $interstate): DispatchLine
    {
        $item = Item::query()->with('stockUom')->findOrFail($line['item_id']);

        if ($item->type !== ItemType::FinishedGood) {
            throw new DispatchException("{$item->name} is not a finished product. Dispatch sends finished goods; material moves by stock transfer.");
        }

        if (! $item->is_active) {
            throw new DispatchException("{$item->name} is inactive.");
        }

        $lot = InventoryLot::query()->with('ownerClient:id,name')->findOrFail($line['lot_id']);

        if ($lot->item_id !== $item->id) {
            throw new DispatchException("Batch {$lot->batch_number} is not a batch of {$item->name}.");
        }

        if (! $lot->isReleasable()) {
            throw new DispatchException("Batch {$lot->batch_number} of {$item->name} is not released: it is {$lot->qc_status->label()}".($lot->isExpired() ? ' and past its expiry' : '').'. Only QC-released, unexpired batches leave.');
        }

        // A client's goods go to that client and nobody else; ours go to anyone.
        if ($lot->owner_client_id !== null && $customer->client_id !== $lot->owner_client_id) {
            throw new DispatchException(sprintf(
                'Batch %s belongs to %s and can only be dispatched to them. Choose the customer linked to %s, or a batch of our own.',
                $lot->batch_number,
                $lot->ownerClient?->name ?? 'a client',
                $lot->ownerClient?->name ?? 'that client',
            ));
        }

        $quantity = $this->toStockUnit($item, BigDecimal::of($line['quantity']), $line['uom_id'] ?? null);

        if (! $quantity->isPositive()) {
            throw new DispatchException("The quantity for {$item->name} must be greater than zero.");
        }

        $available = $this->availableFor($item, $lot, $store);

        if ($available->isLessThan($quantity)) {
            throw new DispatchException(sprintf(
                'Batch %s of %s: only %s %s is free in %s (asked for %s).',
                $lot->batch_number,
                $item->name,
                $available->strippedOfTrailingZeros(),
                $item->stockUom?->code,
                $store->name,
                $quantity->strippedOfTrailingZeros(),
            ));
        }

        $unitPrice = $this->money($line['unit_price']);
        $discount = BigDecimal::of($line['discount_percent'] ?? '0');
        $rate = BigDecimal::of($line['gst_rate'] ?? $item->gst_rate ?? '0');

        if ($discount->isNegative() || $discount->isGreaterThan(100) || $rate->isNegative() || $rate->isGreaterThan(100)) {
            throw new DispatchException("The discount and GST rate for {$item->name} must be between 0 and 100 percent.");
        }

        $taxable = $quantity->multipliedBy($unitPrice)
            ->multipliedBy(BigDecimal::of(100)->minus($discount))
            ->dividedBy(100, self::MONEY_SCALE, RoundingMode::HalfUp);

        $tax = $taxable->multipliedBy($rate)->dividedBy(100, self::MONEY_SCALE, RoundingMode::HalfUp);
        $half = $tax->dividedBy(2, self::MONEY_SCALE, RoundingMode::HalfUp);

        $igst = $interstate ? $tax : BigDecimal::zero();
        $cgst = $interstate ? BigDecimal::zero() : $half;
        // Any odd paisa lands on SGST so the two halves still sum to the tax.
        $sgst = $interstate ? BigDecimal::zero() : $tax->minus($half);

        return $dispatch->lines()->create([
            'line_no' => $lineNo,
            'item_id' => $item->id,
            'lot_id' => $lot->id,
            'uom_id' => $item->stock_uom_id,
            'quantity' => $quantity->__toString(),
            'unit_price' => $unitPrice->__toString(),
            'discount_percent' => $discount->toScale(3, RoundingMode::HalfUp)->__toString(),
            'hsn_code' => $line['hsn_code'] ?? $item->hsn_code,
            'gst_rate' => $rate->toScale(3, RoundingMode::HalfUp)->__toString(),
            'taxable_value' => $taxable->__toString(),
            'cgst' => $cgst->toScale(self::MONEY_SCALE)->__toString(),
            'sgst' => $sgst->toScale(self::MONEY_SCALE)->__toString(),
            'igst' => $igst->toScale(self::MONEY_SCALE)->__toString(),
            'line_total' => $taxable->plus($tax)->toScale(self::MONEY_SCALE)->__toString(),
            'description' => $line['description'] ?? null,
        ]);
    }

    /**
     * Sum the lines into the document, rounding the grand total to the rupee
     * the way an invoice does.
     */
    private function total(Dispatch $dispatch): void
    {
        $lines = $dispatch->lines()->get();

        $sum = fn (string $column): BigDecimal => $lines->reduce(
            fn (BigDecimal $carry, DispatchLine $l) => $carry->plus(BigDecimal::of($l->{$column})),
            BigDecimal::zero(),
        );

        $taxable = $sum('taxable_value');
        $cgst = $sum('cgst');
        $sgst = $sum('sgst');
        $igst = $sum('igst');
        $other = BigDecimal::of($dispatch->other_charges ?? '0');

        $gross = $taxable->plus($cgst)->plus($sgst)->plus($igst)->plus($other);
        $total = $gross->toScale(0, RoundingMode::HalfUp);
        $roundOff = $total->minus($gross);

        $dispatch->forceFill([
            'taxable_value' => $taxable->toScale(self::MONEY_SCALE)->__toString(),
            'cgst' => $cgst->toScale(self::MONEY_SCALE)->__toString(),
            'sgst' => $sgst->toScale(self::MONEY_SCALE)->__toString(),
            'igst' => $igst->toScale(self::MONEY_SCALE)->__toString(),
            'round_off' => $roundOff->toScale(self::MONEY_SCALE)->__toString(),
            'total_value' => $total->toScale(self::MONEY_SCALE)->__toString(),
        ])->save();
    }

    private function availableFor(Item $item, InventoryLot $lot, Warehouse $store): BigDecimal
    {
        return $this->balances->releasableBalances($item, [$store->id], anyOwner: true)
            ->filter(fn (StockBalance $b) => $b->lot_id === $lot->id)
            ->reduce(fn (BigDecimal $carry, StockBalance $b) => $carry->plus($b->available()), BigDecimal::zero());
    }

    private function assertDispatchStore(Warehouse $store): void
    {
        if (! $store->is_active || $store->is_system || $store->is_quarantine) {
            throw new DispatchException("{$store->name} cannot dispatch: it is not an active store, or it is quarantine.");
        }

        if ($store->type !== WarehouseType::FinishedGoods) {
            throw new DispatchException("{$store->name} is not a finished goods store. Goods leave from the finished goods store only; anything else moves by stock transfer.");
        }

        if ($store->facility === null || ! $store->facility->can(FacilityCapability::Dispatch)) {
            throw new DispatchException(($store->facility?->name ?? 'This facility').' cannot dispatch: enable dispatch on the facility first.');
        }
    }

    private function toStockUnit(Item $item, BigDecimal $quantity, ?int $uomId): BigDecimal
    {
        if ($uomId === null || $uomId === $item->stock_uom_id) {
            return $quantity;
        }

        $from = Uom::query()->findOrFail($uomId);

        try {
            return $this->conversions->convert($quantity, $from, $item->stockUom, $item);
        } catch (IncompatibleUnitsException $e) {
            throw new DispatchException("Cannot express {$quantity} {$from->code} of {$item->name} in {$item->stockUom?->code}: {$e->getMessage()}");
        }
    }

    private function stateCode(?string $value): ?string
    {
        $value = trim((string) $value);

        return GstStateCodes::isValid($value) ? $value : null;
    }

    private function money(BigDecimal|string|int|null $value): BigDecimal
    {
        return BigDecimal::of($value ?? '0')->toScale(self::MONEY_SCALE, RoundingMode::HalfUp);
    }

    private function lock(Dispatch $dispatch): Dispatch
    {
        return Dispatch::query()->lockForUpdate()->findOrFail($dispatch->getKey());
    }
}
