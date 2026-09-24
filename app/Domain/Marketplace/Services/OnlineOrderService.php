<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Services;

use App\Domain\Dispatch\Support\GstStateCodes;
use App\Domain\Inventory\DTOs\LedgerLine;
use App\Domain\Inventory\DTOs\LedgerPosting;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Models\InventoryTransaction;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Inventory\Services\InventoryReservationService;
use App\Domain\Inventory\Services\SequenceService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\Marketplace\DTOs\LabelExtraction;
use App\Domain\Marketplace\Enums\LabelBatchStatus;
use App\Domain\Marketplace\Enums\PaymentMode;
use App\Domain\Marketplace\Enums\ShipmentStatus;
use App\Domain\Marketplace\Enums\StockState;
use App\Domain\Marketplace\Exceptions\OnlineOrderException;
use App\Domain\Marketplace\Models\Brand;
use App\Domain\Marketplace\Models\HandoverSheet;
use App\Domain\Marketplace\Models\LabelBatch;
use App\Domain\Marketplace\Models\LabelFile;
use App\Domain\Marketplace\Models\LabelPrint;
use App\Domain\Marketplace\Models\Marketplace;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Marketplace\Models\Shipment;
use App\Domain\Marketplace\Models\ShipmentLine;
use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\Item;
use App\Domain\Warehousing\Enums\FacilityCapability;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The online-orders protocol.
 *
 *   upload → (map) → print → pack → hand over        (or cancel)
 *
 * The agency uploads the marketplace's label PDFs for a brand; each label
 * becomes a parcel and its goods are held in the store it ships from. The
 * depot prints them grouped by courier. A parcel is packed by scanning its
 * label, which is when the stock leaves through the ledger; the courier's
 * pickup is recorded on a handover sheet. Anything printed and not packed
 * stays visible until someone deals with it.
 */
class OnlineOrderService
{
    public const string DISK = 'local';

    public function __construct(
        private readonly LabelReaderService $reader,
        private readonly SequenceService $sequences,
        private readonly InventoryReservationService $reservations,
        private readonly InventoryLedgerService $ledger,
        private readonly StockBalanceService $balances,
    ) {}

    // ---- Upload -----------------------------------------------------------

    /**
     * Add label files to the day's batch for this brand, marketplace and
     * store — opening one if there is none still open.
     *
     * @param  list<UploadedFile>  $files
     */
    public function upload(Brand $brand, Marketplace $marketplace, Warehouse $store, array $files, User $user, ?CarbonImmutable $forDate = null): LabelBatch
    {
        if ($files === []) {
            throw new OnlineOrderException('Choose the label PDF to upload.');
        }

        if (! $brand->is_active || ! $marketplace->is_active) {
            throw new OnlineOrderException("{$brand->name} on {$marketplace->name} is not active.");
        }

        $this->assertDispatchStore($store);
        $forDate ??= CarbonImmutable::now(config('erp.company.timezone', 'Asia/Kolkata'))->startOfDay();

        $batch = LabelBatch::query()
            ->where('brand_id', $brand->id)
            ->where('marketplace_id', $marketplace->id)
            ->where('warehouse_id', $store->id)
            ->whereDate('for_date', $forDate->toDateString())
            ->where('status', LabelBatchStatus::Open->value)
            ->latest('id')
            ->first();

        $batch ??= LabelBatch::create([
            'number' => $this->sequences->nextNumber('LB', $forDate->format('ym')),
            'brand_id' => $brand->id,
            'marketplace_id' => $marketplace->id,
            'facility_id' => $store->facility_id,
            'warehouse_id' => $store->id,
            'for_date' => $forDate->toDateString(),
            'status' => LabelBatchStatus::Open,
            'uploaded_by' => $user->id,
        ]);

        foreach ($files as $file) {
            $this->addFile($batch, $file, $user);
        }

        return $batch->refresh();
    }

    /**
     * Read one PDF into parcels and hold their stock.
     */
    public function addFile(LabelBatch $batch, UploadedFile $upload, User $user): LabelFile
    {
        if (! $batch->isOpen()) {
            throw new OnlineOrderException("{$batch->number} has been closed. Upload into a new batch.");
        }

        $contents = (string) file_get_contents($upload->getRealPath());
        $name = $upload->getClientOriginalName();

        if (! str_starts_with($contents, '%PDF')) {
            throw new OnlineOrderException("{$name} is not a PDF. Upload the label file exactly as the marketplace gave it.");
        }

        $hash = hash('sha256', $contents);
        $earlier = LabelFile::query()->with('batch:id,number,for_date')->where('sha256', $hash)->first();

        if ($earlier !== null) {
            throw new OnlineOrderException(sprintf(
                '%s was already uploaded in %s on %s. Uploading it again would double every order in it.',
                $name,
                $earlier->batch->number,
                $earlier->batch->for_date->format('j M'),
            ));
        }

        $batch->loadMissing(['marketplace', 'brand', 'warehouse.facility']);
        $reading = $this->reader->read($contents, $name, $batch->marketplace);

        $path = sprintf('online-orders/%s/%s.pdf', now()->format('Y/m'), Str::uuid());
        Storage::disk(self::DISK)->put($path, $contents);

        try {
            $file = DB::transaction(function () use ($batch, $upload, $user, $reading, $path, $name, $hash): LabelFile {
                $warnings = $reading->warnings;

                $file = LabelFile::create([
                    'label_batch_id' => $batch->id,
                    'path' => $path,
                    'original_name' => $name,
                    'size' => $upload->getSize() ?: 0,
                    'pages' => $reading->pageCount,
                    'sha256' => $hash,
                    'read_with' => $reading->readWith,
                    'read_model' => $reading->model,
                    'read_at' => now(),
                    'uploaded_by' => $user->id,
                ]);

                $duplicates = [];
                $created = collect();

                foreach ($reading->parcels as $parcel) {
                    if ($parcel->awb !== null && $this->awbTaken($batch->marketplace_id, $parcel->awb)) {
                        $duplicates[] = $parcel->awb;

                        continue;
                    }

                    $created->push($this->createShipment($batch, $file, $parcel));
                }

                if ($created->isEmpty()) {
                    throw new OnlineOrderException($duplicates === []
                        ? "No labels were found in {$name}. Check it is the marketplace's label PDF."
                        : "Every label in {$name} was already uploaded earlier. Nothing new to add.");
                }

                if ($duplicates !== []) {
                    $warnings[] = count($duplicates).' label(s) in this file were already uploaded earlier and were skipped: '.implode(', ', array_slice($duplicates, 0, 10)).(count($duplicates) > 10 ? '…' : '').'.';
                }

                if (($mismatch = $this->registrationMismatch($created, $batch)) !== null) {
                    $warnings[] = $mismatch;
                }

                $file->forceFill(['warnings' => $warnings === [] ? null : array_values($warnings)])->save();

                return $file;
            });
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->delete($path);

            throw $e;
        }

        // Stock is held once the parcels exist, outside the file's
        // transaction, so a store that is short does not undo the upload.
        $file->shipments()->orderBy('id')->get()->each(fn (Shipment $s) => $this->hold($s));

        return $file->refresh();
    }

    /**
     * Take a file back out — the wrong brand, the wrong day — as long as
     * nothing in it has been printed or packed.
     */
    public function removeFile(LabelFile $file): void
    {
        $file->loadMissing(['shipments', 'batch']);

        if ($file->shipments->contains(fn (Shipment $s) => $s->status !== ShipmentStatus::Uploaded)) {
            throw new OnlineOrderException("{$file->original_name} cannot be removed: some of its labels have already been printed or packed. Cancel those parcels one by one instead.");
        }

        DB::transaction(function () use ($file): void {
            foreach ($file->shipments as $shipment) {
                $this->reservations->releaseAllFor($shipment);
                $shipment->lines()->delete();
                $shipment->delete();
            }

            $file->delete();
        });

        Storage::disk(self::DISK)->delete($file->path);
    }

    /**
     * The agency has uploaded everything for the day: tell the depot.
     */
    public function close(LabelBatch $batch, User $user): LabelBatch
    {
        if (! $batch->isOpen()) {
            return $batch;
        }

        if (! $batch->shipments()->exists()) {
            throw new OnlineOrderException("{$batch->number} has no labels yet. Upload the label PDF first.");
        }

        $batch->forceFill([
            'status' => LabelBatchStatus::Closed,
            'closed_at' => now(),
            'closed_by' => $user->id,
        ])->save();

        return $batch->refresh();
    }

    // ---- Mapping and correcting ---------------------------------------------

    /**
     * Say which product a marketplace SKU is. Remembered for every label
     * after this one, and applied now to every parcel still waiting that
     * carries it.
     */
    public function mapSku(Marketplace $marketplace, Brand $brand, string $sellerSku, Item $item, int $unitsPerOrder, User $user): MarketplaceListing
    {
        if ($item->type !== ItemType::FinishedGood) {
            throw new OnlineOrderException("{$item->name} is not a finished product. A marketplace SKU maps to a product.");
        }

        if ($unitsPerOrder < 1) {
            throw new OnlineOrderException('Units per order must be at least one.');
        }

        $sellerSku = trim($sellerSku);
        $key = MarketplaceListing::keyFor($sellerSku);

        $listing = MarketplaceListing::query()->updateOrCreate(
            ['marketplace_id' => $marketplace->id, 'brand_id' => $brand->id, 'sku_key' => $key],
            ['seller_sku' => $sellerSku, 'item_id' => $item->id, 'units_per_order' => $unitsPerOrder, 'is_active' => true, 'created_by' => $user->id],
        );

        $waiting = Shipment::query()
            ->where('marketplace_id', $marketplace->id)
            ->where('brand_id', $brand->id)
            ->whereIn('status', ShipmentStatus::awaitingPacking())
            ->with('lines')
            // First uploaded, first served when stock is short.
            ->orderBy('id')
            ->get()
            ->filter(fn (Shipment $s) => $s->lines->contains(fn (ShipmentLine $l) => MarketplaceListing::keyFor($l->seller_sku) === $key));

        foreach ($waiting as $shipment) {
            $this->reservations->releaseAllFor($shipment);
            $this->mapLines($shipment);
            $this->hold($shipment->refresh());
        }

        return $listing;
    }

    /**
     * Put right what the label reader could not read, or read wrong: the
     * AWB, the courier, the product and quantity.
     *
     * @param  array{awb?: string|null, courier?: string|null, order_number?: string|null, payment_mode?: string|null, lines?: list<array{seller_sku: string, quantity: int}>}  $data
     */
    public function correct(Shipment $shipment, array $data): Shipment
    {
        if (! $shipment->status->awaitsPacking()) {
            throw new OnlineOrderException("{$shipment->reference()} is {$shipment->status->label()}; it can no longer be changed.");
        }

        return DB::transaction(function () use ($shipment, $data): Shipment {
            $awb = isset($data['awb']) ? strtoupper(preg_replace('/\s+/', '', (string) $data['awb']) ?? '') : $shipment->awb;
            $awb = $awb === '' ? null : $awb;

            if ($awb !== null && $awb !== $shipment->awb && $this->awbTaken($shipment->marketplace_id, $awb, $shipment->id)) {
                throw new OnlineOrderException("AWB {$awb} is already on another parcel.");
            }

            $shipment->fill([
                'awb' => $awb,
                'courier' => array_key_exists('courier', $data) ? (trim((string) $data['courier']) ?: null) : $shipment->courier,
                'order_number' => array_key_exists('order_number', $data) ? (trim((string) $data['order_number']) ?: null) : $shipment->order_number,
                'payment_mode' => isset($data['payment_mode']) ? PaymentMode::fromText($data['payment_mode']) : $shipment->payment_mode,
            ])->save();

            if (isset($data['lines'])) {
                $this->reservations->releaseAllFor($shipment);
                $shipment->lines()->delete();

                foreach (array_values($data['lines']) as $i => $line) {
                    $shipment->lines()->create([
                        'line_no' => $i + 1,
                        'seller_sku' => trim($line['seller_sku']),
                        'quantity' => max(1, (int) $line['quantity']),
                    ]);
                }

                $this->mapLines($shipment->refresh());
                $this->hold($shipment->refresh());
            }

            return $shipment->refresh();
        });
    }

    // ---- Stock ------------------------------------------------------------

    /**
     * Hold the parcel's goods in its store, if they are there. A parcel the
     * store cannot cover is marked short and tried again later: at print,
     * at packing, or when someone asks.
     */
    public function hold(Shipment $shipment): StockState
    {
        if (! $shipment->status->awaitsPacking()) {
            return $shipment->stock_state;
        }

        $shipment->loadMissing(['lines.item.stockUom', 'brand', 'warehouse']);

        if (! $shipment->isMapped()) {
            $this->setStockState($shipment, StockState::Unmapped);

            return StockState::Unmapped;
        }

        $held = $this->reservations->outstandingFor($shipment);
        $needed = $shipment->lines->reduce(fn (BigDecimal $c, ShipmentLine $l) => $c->plus(BigDecimal::of($l->units)), BigDecimal::zero());

        if ($held->isEqualTo($needed) && $held->isPositive()) {
            $this->setStockState($shipment, StockState::Reserved);

            return StockState::Reserved;
        }

        $this->reservations->releaseAllFor($shipment);

        try {
            DB::transaction(function () use ($shipment): void {
                foreach ($shipment->lines as $line) {
                    $this->reservations->reserve(
                        $shipment,
                        $line->item,
                        $shipment->warehouse,
                        $line->units,
                        notes: "{$shipment->reference()} ({$line->seller_sku})",
                        ownerClientId: $shipment->brand->client_id,
                    );
                }
            });
        } catch (InsufficientStockException) {
            $this->setStockState($shipment, StockState::Short);

            return StockState::Short;
        }

        $this->setStockState($shipment, StockState::Reserved);

        return StockState::Reserved;
    }

    /**
     * Try again for every parcel in a batch still waiting on stock.
     *
     * @return array{held: int, short: int, unmapped: int}
     */
    public function holdAgain(LabelBatch $batch): array
    {
        $counts = ['held' => 0, 'short' => 0, 'unmapped' => 0];

        $batch->shipments()
            ->whereIn('status', ShipmentStatus::awaitingPacking())
            ->whereIn('stock_state', [StockState::Short->value, StockState::Unmapped->value, StockState::Released->value])
            ->orderBy('id')
            ->get()
            ->each(function (Shipment $s) use (&$counts): void {
                $this->mapLines($s);
                $state = $this->hold($s->refresh());
                $counts[match ($state) {
                    StockState::Reserved => 'held',
                    StockState::Short => 'short',
                    default => 'unmapped',
                }]++;
            });

        return $counts;
    }

    /**
     * What the batch needs against what the store has, product by product —
     * so a transfer can be raised before anyone starts packing.
     *
     * @return list<array{item_id: int, code: string, name: string, unit: string|null, needed: string, free: string, short: string}>
     */
    public function shortfall(LabelBatch $batch): array
    {
        $needed = ShipmentLine::query()
            ->join('shipments', 'shipments.id', '=', 'shipment_lines.shipment_id')
            ->where('shipments.label_batch_id', $batch->id)
            ->where('shipments.stock_state', StockState::Short->value)
            ->whereIn('shipments.status', ShipmentStatus::awaitingPacking())
            ->whereNotNull('shipment_lines.item_id')
            ->groupBy('shipment_lines.item_id')
            ->selectRaw('shipment_lines.item_id, SUM(shipment_lines.units) AS units')
            ->pluck('units', 'item_id');

        if ($needed->isEmpty()) {
            return [];
        }

        $batch->loadMissing(['warehouse', 'brand']);
        $items = Item::query()->with('stockUom:id,code')->whereIn('id', $needed->keys())->get()->keyBy('id');
        $rows = [];

        foreach ($needed as $itemId => $units) {
            $item = $items->get($itemId);

            if ($item === null) {
                continue;
            }

            $free = $this->balances->releasableBalances($item, [$batch->warehouse_id], ownerClientId: $batch->brand->client_id)
                ->reduce(fn (BigDecimal $c, $b) => $c->plus($b->available()), BigDecimal::zero());
            $need = BigDecimal::of((string) $units);

            $rows[] = [
                'item_id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'unit' => $item->stockUom?->code,
                'needed' => (string) $need->strippedOfTrailingZeros(),
                'free' => (string) $free->strippedOfTrailingZeros(),
                'short' => (string) ($need->isGreaterThan($free) ? $need->minus($free) : BigDecimal::zero())->strippedOfTrailingZeros(),
            ];
        }

        return $rows;
    }

    // ---- Print ------------------------------------------------------------

    /**
     * Record a print run and say which pages of which files to print, in
     * courier order. The browser assembles the pages from the originals.
     *
     * @param  'all'|'unprinted'|'courier'|'one'  $scope
     * @return array{shipments: int, pages: int, parts: list<array{file_id: int, pages: list<int>, courier: string|null, shipment_id: int}>}
     */
    public function print(LabelBatch $batch, string $scope, User $user, ?string $courier = null, ?int $shipmentId = null): array
    {
        $shipments = $batch->shipments()
            ->where('status', '<>', ShipmentStatus::Cancelled->value)
            ->when($scope === 'unprinted', fn ($q) => $q->where('status', ShipmentStatus::Uploaded->value))
            ->when($scope === 'courier', fn ($q) => $courier === null || $courier === '' ? $q->whereNull('courier') : $q->where('courier', $courier))
            ->when($scope === 'one', fn ($q) => $q->whereKey($shipmentId))
            ->get()
            ->sortBy([
                fn (Shipment $a, Shipment $b) => strcmp((string) $a->courier, (string) $b->courier),
                fn (Shipment $a, Shipment $b) => [$a->label_file_id, $a->pages[0] ?? 0] <=> [$b->label_file_id, $b->pages[0] ?? 0],
            ])
            ->values();

        if ($shipments->isEmpty()) {
            throw new OnlineOrderException($scope === 'unprinted' ? 'Every label in this batch has been printed already.' : 'There are no labels to print for that choice.');
        }

        DB::transaction(function () use ($batch, $shipments, $scope, $courier, $user): void {
            $now = now();

            foreach ($shipments as $shipment) {
                $shipment->fill([
                    'status' => $shipment->status === ShipmentStatus::Uploaded ? ShipmentStatus::Printed : $shipment->status,
                    'printed_at' => $shipment->printed_at ?? $now,
                    'printed_by' => $shipment->printed_by ?? $user->id,
                    'print_count' => $shipment->print_count + 1,
                ])->save();
            }

            LabelPrint::create([
                'label_batch_id' => $batch->id,
                'printed_by' => $user->id,
                'scope' => $scope,
                'courier' => $scope === 'courier' ? $courier : null,
                'shipment_count' => $shipments->count(),
                'page_count' => $shipments->sum(fn (Shipment $s) => count($s->pages)),
            ]);
        });

        // Stock may have come in since the upload.
        $shipments->filter(fn (Shipment $s) => in_array($s->stock_state, [StockState::Short, StockState::Unmapped], true))
            ->each(fn (Shipment $s) => $this->hold($s));

        return [
            'shipments' => $shipments->count(),
            'pages' => $shipments->sum(fn (Shipment $s) => count($s->pages)),
            'parts' => $shipments->map(fn (Shipment $s) => [
                'shipment_id' => $s->id,
                'file_id' => $s->label_file_id,
                'pages' => array_values($s->pages),
                'courier' => $s->courier,
            ])->all(),
        ];
    }

    // ---- Pack -------------------------------------------------------------

    /**
     * Find the parcel a scanned label belongs to.
     */
    public function findByCode(string $code): ?Shipment
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        return Shipment::query()
            ->matchingCode($code)
            ->with(['lines.item:id,code,name', 'marketplace:id,name', 'brand:id,name', 'warehouse:id,code,name,facility_id', 'packer:id,name'])
            ->get()
            // A parcel still waiting beats one already dealt with.
            ->sortBy(fn (Shipment $s) => [$s->status->awaitsPacking() ? 0 : ($s->status === ShipmentStatus::Cancelled ? 2 : 1), -$s->id])
            ->first();
    }

    /**
     * The goods are in the box. The stock leaves the store now, batch by
     * batch, against the parcel.
     */
    public function pack(Shipment $shipment, User $user, string $method = 'scan', ?string $note = null): Shipment
    {
        if ($method === 'manual' && trim((string) $note) === '') {
            throw new OnlineOrderException('Say why this parcel is being marked packed without scanning its label.');
        }

        return DB::transaction(function () use ($shipment, $user, $method, $note): Shipment {
            $shipment = Shipment::query()->lockForUpdate()->with(['lines.item.stockUom', 'brand', 'warehouse', 'marketplace'])->findOrFail($shipment->id);

            if ($shipment->status === ShipmentStatus::Cancelled) {
                throw new OnlineOrderException("Do not pack this parcel: order {$shipment->order_number} was cancelled".($shipment->cancel_reason ? " ({$shipment->cancel_reason})" : '').'.');
            }

            if ($shipment->status->isPacked()) {
                throw new OnlineOrderException("Already packed{$this->byWhom($shipment)}. Do not pack it twice.");
            }

            if (! $shipment->isMapped()) {
                throw new OnlineOrderException('The ERP does not know which product this label is for yet. Ask the office to map "'.($shipment->lines->first()?->seller_sku ?? 'the SKU').'" first.');
            }

            if ($this->hold($shipment) !== StockState::Reserved) {
                $line = $shipment->lines->first();

                throw new OnlineOrderException(sprintf(
                    'Not enough %s in %s to pack this parcel. Bring stock in first.',
                    $line?->item?->name ?? 'stock',
                    $shipment->warehouse->name,
                ));
            }

            $transaction = $this->consume($shipment, $user);

            $shipment->fill([
                'status' => ShipmentStatus::Packed,
                'stock_state' => StockState::Consumed,
                'packed_at' => now(),
                'packed_by' => $user->id,
                'pack_method' => $method,
                'pack_note' => $note,
            ])->save();

            unset($transaction);

            return $shipment->refresh()->load(['lines.item:id,code,name', 'marketplace:id,name', 'brand:id,name', 'packer:id,name']);
        });
    }

    // ---- Hand over ----------------------------------------------------------

    /**
     * The courier has collected these parcels. One sheet, one signature.
     *
     * @param  list<int>  $shipmentIds
     */
    public function handOver(Warehouse $store, string $courier, array $shipmentIds, User $user, ?string $receivedBy = null): HandoverSheet
    {
        return DB::transaction(function () use ($store, $courier, $shipmentIds, $user, $receivedBy): HandoverSheet {
            $shipments = Shipment::query()
                ->lockForUpdate()
                ->whereKey($shipmentIds)
                ->where('warehouse_id', $store->id)
                ->get();

            if ($shipments->isEmpty()) {
                throw new OnlineOrderException('Choose the parcels the courier is taking.');
            }

            $notPacked = $shipments->filter(fn (Shipment $s) => $s->status !== ShipmentStatus::Packed);

            if ($notPacked->isNotEmpty()) {
                throw new OnlineOrderException('Only packed parcels can be handed over. Not packed: '.$notPacked->map(fn (Shipment $s) => $s->reference())->take(5)->implode(', ').'.');
            }

            $now = now();
            $sheet = HandoverSheet::create([
                'number' => $this->sequences->nextNumber('HO', $now->format('ym')),
                'facility_id' => $store->facility_id,
                'warehouse_id' => $store->id,
                'courier' => $courier,
                'shipment_count' => $shipments->count(),
                'received_by_name' => $receivedBy !== null && trim($receivedBy) !== '' ? trim($receivedBy) : null,
                'handed_over_by' => $user->id,
                'handed_over_at' => $now,
            ]);

            foreach ($shipments as $shipment) {
                $shipment->fill([
                    'status' => ShipmentStatus::HandedOver,
                    'handed_over_at' => $now,
                    'handed_over_by' => $user->id,
                    'handover_sheet_id' => $sheet->id,
                ])->save();
            }

            return $sheet;
        });
    }

    // ---- Cancel -----------------------------------------------------------

    /**
     * The marketplace cancelled the order. Before packing, what was held is
     * let go; after packing, and before the courier has it, the goods go
     * back on the shelf by reversing the posting.
     */
    public function cancel(Shipment $shipment, string $reason, User $user): Shipment
    {
        if (trim($reason) === '') {
            throw new OnlineOrderException('Say why the parcel is cancelled.');
        }

        return DB::transaction(function () use ($shipment, $reason, $user): Shipment {
            $shipment = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);

            if ($shipment->status === ShipmentStatus::Cancelled) {
                return $shipment;
            }

            if ($shipment->status === ShipmentStatus::HandedOver) {
                throw new OnlineOrderException("{$shipment->reference()} is with the courier. It comes back as a return, not a cancellation.");
            }

            if ($shipment->status === ShipmentStatus::Packed) {
                $posting = $shipment->transactions()->where('type', InventoryTransactionType::MarketplaceSale->value)->latest('id')->first();

                if ($posting !== null) {
                    $this->ledger->reverse($posting, "Parcel {$shipment->reference()} unpacked and cancelled: {$reason}", $user->id);
                }
            } else {
                $this->reservations->releaseAllFor($shipment);
            }

            $shipment->fill([
                'status' => ShipmentStatus::Cancelled,
                'stock_state' => StockState::Released,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => trim($reason),
            ])->save();

            return $shipment->refresh();
        });
    }

    // ---- Internals ----------------------------------------------------------

    private function createShipment(LabelBatch $batch, LabelFile $file, LabelExtraction $parcel): Shipment
    {
        $shipment = Shipment::create([
            'label_batch_id' => $batch->id,
            'label_file_id' => $file->id,
            'marketplace_id' => $batch->marketplace_id,
            'brand_id' => $batch->brand_id,
            'warehouse_id' => $batch->warehouse_id,
            'pages' => $parcel->pages,
            'awb' => $parcel->awb,
            'alt_code' => $parcel->altCode,
            'order_number' => $parcel->orderNumber,
            'courier' => $parcel->courier,
            'payment_mode' => $parcel->paymentMode,
            'payable_amount' => $parcel->payableAmount,
            'invoice_number' => $parcel->invoiceNumber,
            'invoice_date' => $parcel->invoiceDate,
            'customer_name' => $parcel->customerName,
            'customer_state' => $parcel->customerState,
            'seller_gstin' => $parcel->sellerGstin,
            'status' => ShipmentStatus::Uploaded,
            'stock_state' => StockState::Unmapped,
            'extraction' => $parcel->toArray(),
            'warnings' => $this->parcelWarnings($parcel),
        ]);

        foreach ($parcel->lines as $i => $line) {
            $shipment->lines()->create([
                'line_no' => $i + 1,
                'seller_sku' => $line['seller_sku'],
                'description' => $line['description'] !== null ? Str::limit($line['description'], 250, '') : null,
                'quantity' => $line['quantity'],
            ]);
        }

        $this->mapLines($shipment);

        return $shipment;
    }

    /**
     * @return list<string>|null
     */
    private function parcelWarnings(LabelExtraction $parcel): ?array
    {
        $warnings = $parcel->warnings;

        if ($parcel->awb === null && $parcel->warnings === []) {
            $warnings[] = 'No AWB was read from this label. Type it in so the label can be scanned at packing.';
        }

        if ($parcel->lines === []) {
            $warnings[] = 'The label does not say what goes in the parcel. Add the product before it is packed.';
        }

        return $warnings === [] ? null : array_values(array_unique($warnings));
    }

    /**
     * Match each line to a product through the brand's listings on this
     * marketplace, and work out the units to take from the shelf.
     */
    private function mapLines(Shipment $shipment): void
    {
        $shipment->loadMissing('lines');

        $listings = MarketplaceListing::query()
            ->where('marketplace_id', $shipment->marketplace_id)
            ->where('brand_id', $shipment->brand_id)
            ->where('is_active', true)
            ->get()
            ->keyBy('sku_key');

        foreach ($shipment->lines as $line) {
            $listing = $listings->get(MarketplaceListing::keyFor($line->seller_sku));

            $line->fill([
                'listing_id' => $listing?->id,
                'item_id' => $listing?->item_id,
                'units' => $listing === null ? null : (string) BigDecimal::of($line->quantity)->multipliedBy($listing->units_per_order),
            ])->save();
        }

        $shipment->unsetRelation('lines');
    }

    /**
     * Lower what is held and post it out, one line per batch, in one posting.
     */
    private function consume(Shipment $shipment, User $user): InventoryTransaction
    {
        $held = StockReservation::query()
            ->lockForUpdate()
            ->active()
            ->where('reservable_type', $shipment->getMorphClass())
            ->where('reservable_id', $shipment->id)
            ->orderBy('id')
            ->get();

        $lines = [];

        foreach ($held as $reservation) {
            $quantity = $reservation->outstanding();

            if (! $quantity->isPositive()) {
                continue;
            }

            // The reserved figure comes down first, so the ledger's own
            // "not below reserved" check sees the stock as free to leave.
            $balance = $this->ledger->lockBalance($reservation->item_id, $reservation->warehouse_id, $reservation->lot_id);
            $balance->forceFill(['reserved' => (string) $balance->reserved()->minus($quantity)])->save();

            $reservation->forceFill([
                'consumed_quantity' => (string) $reservation->consumed()->plus($quantity),
                'status' => ReservationStatus::Consumed,
                'closed_at' => now(),
            ])->save();

            $lines[] = new LedgerLine($reservation->item_id, $reservation->warehouse_id, $quantity->negated(), $reservation->lot_id);
        }

        if ($lines === []) {
            throw new OnlineOrderException('No stock is held for this parcel. Check stock again and retry.');
        }

        return $this->ledger->post(new LedgerPosting(
            type: InventoryTransactionType::MarketplaceSale,
            warehouseId: $shipment->warehouse_id,
            lines: $lines,
            reference: $shipment,
            reason: sprintf(
                'Packed for %s order %s, AWB %s',
                $shipment->marketplace->name,
                $shipment->order_number ?? '—',
                $shipment->awb ?? '—',
            ),
            createdBy: $user->id,
        ));
    }

    private function setStockState(Shipment $shipment, StockState $state): void
    {
        if ($shipment->stock_state !== $state) {
            $shipment->forceFill(['stock_state' => $state])->save();
        }
    }

    private function awbTaken(int $marketplaceId, string $awb, ?int $except = null): bool
    {
        return Shipment::query()
            ->where('marketplace_id', $marketplaceId)
            ->where('awb', $awb)
            ->where('status', '<>', ShipmentStatus::Cancelled->value)
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except))
            ->exists();
    }

    /**
     * Labels registered to ship from one state while the stock leaves from
     * another. Worth saying; not ours to refuse.
     *
     * @param  Collection<int, Shipment>  $shipments
     */
    private function registrationMismatch(Collection $shipments, LabelBatch $batch): ?string
    {
        $from = GstStateCodes::fromGstin($batch->warehouse->facility->gstin);

        if ($from === null) {
            return null;
        }

        $states = $shipments->pluck('seller_gstin')->filter()->map(fn (string $g) => GstStateCodes::fromGstin($g))->filter()->unique();
        $other = $states->reject(fn (string $s) => $s === $from);

        if ($other->isEmpty()) {
            return null;
        }

        return sprintf(
            'These labels are registered to ship from %s (seller GSTIN %s…), but the stock will leave %s in %s. Check the upload chose the right store.',
            $other->map(fn (string $s) => GstStateCodes::name($s) ?? $s)->implode(', '),
            $other->first(),
            $batch->warehouse->name,
            GstStateCodes::name($from) ?? $from,
        );
    }

    private function byWhom(Shipment $shipment): string
    {
        $shipment->loadMissing('packer:id,name');

        return ($shipment->packer ? " by {$shipment->packer->name}" : '').($shipment->packed_at ? ' at '.$shipment->packed_at->timezone(config('erp.company.timezone', 'Asia/Kolkata'))->format('j M, g:i A') : '');
    }

    private function assertDispatchStore(Warehouse $store): void
    {
        $store->loadMissing('facility');

        if (! $store->is_active || $store->is_system || $store->is_quarantine || $store->type !== WarehouseType::FinishedGoods) {
            throw new OnlineOrderException("{$store->name} is not a finished goods store parcels can leave from.");
        }

        if (! $store->facility?->can(FacilityCapability::Dispatch)) {
            throw new OnlineOrderException("{$store->facility?->name} is not set up to dispatch. Turn on dispatch for the facility first.");
        }
    }
}
