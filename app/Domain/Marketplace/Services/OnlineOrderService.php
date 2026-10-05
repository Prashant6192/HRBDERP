<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Services;

use App\Domain\Dispatch\Support\GstStateCodes;
use App\Domain\Inventory\DTOs\LedgerLine;
use App\Domain\Inventory\DTOs\LedgerPosting;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\InventoryTransaction;
use App\Domain\Inventory\Models\StockBalance;
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
use App\Domain\Marketplace\Models\ShipmentPick;
use App\Domain\Marketplace\Support\LabelFileStore;
use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\Item;
use App\Domain\Warehousing\Enums\FacilityCapability;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use App\Support\Math\Decimal;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
    public const string DISK = LabelFileStore::DISK;

    public function __construct(
        private readonly LabelReaderService $reader,
        private readonly SequenceService $sequences,
        private readonly InventoryReservationService $reservations,
        private readonly InventoryLedgerService $ledger,
        private readonly StockBalanceService $balances,
        private readonly LabelFileStore $files,
    ) {}

    // ---- Upload -----------------------------------------------------------

    /**
     * Add label files to the day's batch for this brand, marketplace and
     * store — opening one if there is none still open.
     *
     * @param  list<UploadedFile>  $files
     * @param  array<string, array<int, string>>  $seen  what the browser read on each file's picture pages, by the file's SHA-256
     */
    public function upload(Brand $brand, Marketplace $marketplace, Warehouse $store, array $files, User $user, ?CarbonImmutable $forDate = null, array $seen = []): LabelBatch
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
            $this->addFile($batch, $file, $user, $seen);
        }

        return $batch->refresh();
    }

    /**
     * A label PDF uploaded before whose copy has been lost is put back when
     * the same file is uploaded again, instead of being refused as a
     * duplicate. Returns the files put back; the rest are left to upload.
     *
     * @param  list<UploadedFile>  $uploads
     * @return array{restored: list<LabelFile>, rest: list<UploadedFile>}
     */
    public function restoreMissing(array $uploads): array
    {
        $restored = [];
        $rest = [];

        foreach ($uploads as $upload) {
            $contents = (string) file_get_contents($upload->getRealPath());
            $earlier = str_starts_with($contents, '%PDF')
                ? LabelFile::query()->with('batch:id,number,for_date')->where('sha256', hash('sha256', $contents))->first()
                : null;

            if ($earlier !== null && ! $this->files->has($earlier)) {
                $this->files->put($earlier, $contents);
                $restored[] = $earlier;

                continue;
            }

            $rest[] = $upload;
        }

        return ['restored' => $restored, 'rest' => $rest];
    }

    /**
     * Read one PDF into parcels and hold their stock.
     *
     * @param  array<string, array<int, string>>  $seen  what the browser read on picture pages, by the file's SHA-256
     */
    public function addFile(LabelBatch $batch, UploadedFile $upload, User $user, array $seen = []): LabelFile
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
        $reading = $this->reader->read($contents, $name, $batch->marketplace, $seen[$hash] ?? []);

        $path = sprintf('online-orders/%s/%s.pdf', now()->format('Y/m'), Str::uuid());

        try {
            $file = DB::transaction(function () use ($batch, $upload, $user, $reading, $path, $name, $hash, $contents): LabelFile {
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

                // Kept on the disk and in the database: the disk does not
                // survive a deploy.
                $this->files->put($file, $contents);

                $duplicates = [];
                $created = collect();

                foreach ($reading->parcels as $parcel) {
                    if ($parcel->awb !== null && $this->awbTaken($batch->marketplace_id, $parcel->awb)) {
                        $duplicates[] = $parcel->awb;

                        continue;
                    }

                    // An invoice on its own (Myntra) has no AWB: its order
                    // number says whether it came before.
                    if ($parcel->awb === null && $parcel->orderNumber !== null && $this->orderTaken($batch->marketplace_id, $parcel->orderNumber)) {
                        $duplicates[] = $parcel->orderNumber;

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
            $this->files->discard($path);

            throw $e;
        }

        // A label and its invoice sent as two files (Myntra) become one
        // parcel as soon as both are in.
        $this->pairSplitParcels($batch);

        // Stock is held once the parcels exist, outside the file's
        // transaction, so a store that is short does not undo the upload.
        $file->shipments()->orderBy('id')->get()->each(fn (Shipment $s) => $this->hold($s));

        return $file->refresh();
    }

    /**
     * Take a file back out — the wrong brand, the wrong marketplace, the
     * wrong day — as long as none of its parcels has been scanned: printing
     * only used paper, while a scan has taken stock out.
     */
    public function removeFile(LabelFile $file): void
    {
        $file->loadMissing(['shipments', 'batch']);

        $moved = [ShipmentStatus::Packed, ShipmentStatus::HandedOver, ShipmentStatus::Returned];

        if ($file->shipments->contains(fn (Shipment $s) => in_array($s->status, $moved, true))) {
            throw new OnlineOrderException("{$file->original_name} cannot be removed: some of its parcels have already been scanned, dispatched or returned. Cancel those parcels one by one instead.");
        }

        DB::transaction(function () use ($file): void {
            foreach ($file->shipments as $shipment) {
                $this->reservations->releaseAllFor($shipment);
                $shipment->lines()->delete();
                $shipment->delete();
            }

            $this->files->forget($file);
            $file->delete();
        });
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
        return $this->mapSkuTo($marketplace, $brand, $sellerSku, [['item' => $item, 'units' => $unitsPerOrder]], $user);
    }

    /**
     * Say what one order of a marketplace SKU holds: one product ("pack of
     * 2" is one product, two pieces) or several (a combo). Applied now to
     * every waiting parcel that carries it, first uploaded first served.
     *
     * @param  list<array{item: Item, units: int}>  $components
     */
    public function mapSkuTo(Marketplace $marketplace, Brand $brand, string $sellerSku, array $components, User $user): MarketplaceListing
    {
        if ($components === []) {
            throw new OnlineOrderException('Choose the product this SKU is.');
        }

        $seen = [];

        foreach ($components as $component) {
            $item = $component['item'];

            if ($item->type !== ItemType::FinishedGood) {
                throw new OnlineOrderException("{$item->name} is not a finished product. A marketplace SKU maps to products.");
            }

            if ($component['units'] < 1) {
                throw new OnlineOrderException("Pieces of {$item->name} must be at least one.");
            }

            if (isset($seen[$item->id])) {
                throw new OnlineOrderException("{$item->name} is listed twice. Put all its pieces on one line.");
            }

            $seen[$item->id] = true;
        }

        $sellerSku = trim($sellerSku);
        $key = MarketplaceListing::keyFor($sellerSku);
        $first = $components[0];

        $listing = DB::transaction(function () use ($marketplace, $brand, $sellerSku, $key, $first, $components, $user): MarketplaceListing {
            $listing = MarketplaceListing::query()->updateOrCreate(
                ['marketplace_id' => $marketplace->id, 'brand_id' => $brand->id, 'sku_key' => $key],
                ['seller_sku' => $sellerSku, 'item_id' => $first['item']->id, 'units_per_order' => $first['units'], 'is_active' => true, 'created_by' => $user->id],
            );

            $listing->components()->delete();

            foreach ($components as $i => $component) {
                $listing->components()->create([
                    'item_id' => $component['item']->id,
                    'units_per_order' => $component['units'],
                    'line_no' => $i + 1,
                ]);
            }

            return $listing;
        });

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

        return $listing->refresh()->load('components.item');
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

        $shipment->loadMissing(['lines', 'picks.item.stockUom', 'brand', 'warehouse']);

        if (! $shipment->isMapped()) {
            $this->setStockState($shipment, StockState::Unmapped);

            return StockState::Unmapped;
        }

        $held = $this->reservations->outstandingFor($shipment);
        $needed = $shipment->picks->reduce(fn (BigDecimal $c, ShipmentPick $p) => $c->plus(BigDecimal::of($p->units)), BigDecimal::zero());

        if ($held->isEqualTo($needed) && $held->isPositive()) {
            $this->setStockState($shipment, StockState::Reserved);

            return StockState::Reserved;
        }

        $this->reservations->releaseAllFor($shipment);

        try {
            DB::transaction(function () use ($shipment): void {
                foreach ($shipment->picks as $pick) {
                    $this->reservations->reserve(
                        $shipment,
                        $pick->item,
                        $shipment->warehouse,
                        $pick->units,
                        notes: "{$shipment->reference()} ({$pick->item->name})",
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
     * @return list<array{item_id: int, code: string, name: string, unit: string|null, store: string|null, needed: string, free: string, short: string, why: list<string>}>
     */
    public function shortfall(LabelBatch $batch): array
    {
        return $this->shortfallFor(collect([$batch]));
    }

    /**
     * The same, across several batches, one row per store and product, with
     * the reasons the store cannot cover it: where the stock is instead.
     *
     * @param  Collection<int, LabelBatch>  $batches
     * @return list<array{item_id: int, code: string, name: string, unit: string|null, store: string|null, needed: string, free: string, short: string, why: list<string>}>
     */
    public function shortfallFor(Collection $batches): array
    {
        if ($batches->isEmpty()) {
            return [];
        }

        $needed = ShipmentPick::query()
            ->join('shipments', 'shipments.id', '=', 'shipment_picks.shipment_id')
            ->whereIn('shipments.label_batch_id', $batches->pluck('id'))
            ->where('shipments.stock_state', StockState::Short->value)
            ->whereIn('shipments.status', ShipmentStatus::awaitingPacking())
            ->groupBy('shipments.warehouse_id', 'shipments.brand_id', 'shipment_picks.item_id')
            ->selectRaw('shipments.warehouse_id, shipments.brand_id, shipment_picks.item_id, SUM(shipment_picks.units) AS units')
            ->toBase()
            ->get();

        if ($needed->isEmpty()) {
            return [];
        }

        $items = Item::query()->with('stockUom:id,code')->whereIn('id', $needed->pluck('item_id')->unique())->get()->keyBy('id');
        $stores = Warehouse::query()->with('facility:id,name')->whereIn('id', $needed->pluck('warehouse_id')->unique())->get()->keyBy('id');
        $owners = Brand::query()->whereIn('id', $needed->pluck('brand_id')->unique())->pluck('client_id', 'id');
        $rows = [];

        // One row per store and product; brands of one owner share stock.
        foreach ($needed->groupBy(fn ($r) => $r->warehouse_id.'-'.$r->item_id.'-'.($owners[$r->brand_id] ?? '')) as $group) {
            $first = $group->first();
            $item = $items->get($first->item_id);
            $store = $stores->get($first->warehouse_id);

            if ($item === null || $store === null) {
                continue;
            }

            $owner = $owners[$first->brand_id] ?? null;
            $free = $this->balances->releasableBalances($item, [$store->id], ownerClientId: $owner)
                ->reduce(fn (BigDecimal $c, $b) => $c->plus($b->available()), BigDecimal::zero());
            $need = $group->reduce(fn (BigDecimal $c, $r) => $c->plus(BigDecimal::of((string) $r->units)), BigDecimal::zero());

            $rows[] = [
                'item_id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'unit' => $item->stockUom?->code,
                'store' => $store->name,
                'needed' => (string) $need->strippedOfTrailingZeros(),
                'free' => (string) $free->strippedOfTrailingZeros(),
                'short' => (string) ($need->isGreaterThan($free) ? $need->minus($free) : BigDecimal::zero())->strippedOfTrailingZeros(),
                'why' => $this->whyShort($item, $store, $owner, $free, $need),
            ];
        }

        return $rows;
    }

    /**
     * Where the product is, if not free in this store: held for other
     * parcels, waiting for QC, past expiry, someone else's, in another
     * store — or booked under a product with nearly the same name.
     *
     * @return list<string>
     */
    private function whyShort(Item $item, Warehouse $store, ?int $owner, BigDecimal $free, BigDecimal $need): array
    {
        $unit = $item->stockUom?->code ?? '';
        $fmt = fn (BigDecimal $q): string => trim(Decimal::strip((string) $q).' '.$unit);
        $why = [];

        if ($free->isPositive() && $free->isGreaterThanOrEqualTo($need)) {
            $why[] = 'There is enough now — press Check stock again.';
        }

        $here = StockBalance::query()->where('item_id', $item->id)->where('warehouse_id', $store->id)->where('on_hand', '>', 0)->get();
        $lots = InventoryLot::query()->whereIn('id', $here->pluck('lot_id')->filter()->unique())->get()->keyBy('id');

        $held = BigDecimal::zero();
        $qc = BigDecimal::zero();
        $expired = BigDecimal::zero();
        $others = BigDecimal::zero();

        foreach ($here as $balance) {
            $lot = $balance->lot_id === null ? null : $lots->get($balance->lot_id);

            if ($lot !== null && ! $lot->qc_status->isReleasable()) {
                $qc = $qc->plus($balance->onHand());
            } elseif ($lot !== null && $lot->isExpired()) {
                $expired = $expired->plus($balance->onHand());
            } elseif (($lot?->owner_client_id ?? null) !== $owner) {
                $others = $others->plus($balance->onHand());
            } else {
                $held = $held->plus($balance->reserved());
            }
        }

        if ($held->isPositive()) {
            $why[] = "{$fmt($held)} in {$store->name} is already held for other parcels or orders.";
        }

        if ($qc->isPositive()) {
            $why[] = "{$fmt($qc)} in {$store->name} is waiting for QC — pass the batch to use it.";
        }

        if ($expired->isPositive()) {
            $why[] = "{$fmt($expired)} in {$store->name} is past its expiry date.";
        }

        if ($others->isPositive()) {
            $why[] = "{$fmt($others)} in {$store->name} belongs to another owner (a 3P client or the company), not this brand.";
        }

        $elsewhere = StockBalance::query()
            ->where('item_id', $item->id)
            ->where('warehouse_id', '!=', $store->id)
            ->where('on_hand', '>', 0)
            ->selectRaw('warehouse_id, SUM(on_hand) AS qty')
            ->groupBy('warehouse_id')
            ->orderByDesc('qty')
            ->limit(3)
            ->toBase()
            ->get();

        if ($elsewhere->isNotEmpty()) {
            $names = Warehouse::query()->with('facility:id,name')->whereIn('id', $elsewhere->pluck('warehouse_id'))->get()->keyBy('id');
            $where = $elsewhere->map(function ($r) use ($names, $fmt): string {
                $w = $names->get($r->warehouse_id);

                return $fmt(BigDecimal::of((string) $r->qty)).' in '.($w?->name ?? 'another store').($w?->facility ? " ({$w->facility->name})" : '');
            })->implode('; ');
            $why[] = "Stock is in another store: {$where}. Move it here with a stock transfer.";
        }

        foreach ($this->lookAlikes($item, $store) as $alike) {
            $why[] = "{$alike['name']} ({$alike['code']}) has {$alike['qty']} in {$store->name}. If that is the same product, the SKU is mapped to the wrong one — fix it in SKU mapping, or book the stock under {$item->code}.";
        }

        if ($why === []) {
            $why[] = "No stock of {$item->code} anywhere yet. Book it in, or transfer it from the factory.";
        }

        return $why;
    }

    /**
     * Other products with stock in the store whose name reads the same:
     * the same pack size and a shared word, or one name inside the other.
     *
     * @return list<array{code: string, name: string, qty: string}>
     */
    private function lookAlikes(Item $item, Warehouse $store): array
    {
        $normal = fn (string $name): string => (string) preg_replace('/[^a-z0-9]/', '', strtolower($name));
        $size = fn (string $name): ?string => preg_match('/(\d+(?:\.\d+)?)\s*(ml|l|ltr|litre|g|gm|gms|kg)\b/i', $name, $m) ? $m[1].strtolower(rtrim($m[2], 's')) : null;
        $words = fn (string $name): array => array_values(array_filter(
            preg_split('/[^a-z]+/', strtolower($name)) ?: [],
            fn (string $w) => strlen($w) >= 4,
        ));

        $mine = $normal($item->name);
        $mySize = $size($item->name);
        $myWords = $words($item->name);

        return StockBalance::query()
            ->join('items as i', 'i.id', '=', 'stock_balances.item_id')
            ->where('stock_balances.warehouse_id', $store->id)
            ->where('stock_balances.item_id', '!=', $item->id)
            ->where('stock_balances.on_hand', '>', 0)
            ->whereNull('i.deleted_at')
            ->groupBy('i.id', 'i.code', 'i.name')
            ->selectRaw('i.code, i.name, SUM(stock_balances.on_hand) AS qty')
            ->toBase()
            ->get()
            ->filter(function ($r) use ($normal, $size, $words, $mine, $mySize, $myWords): bool {
                $theirs = $normal((string) $r->name);

                if ($mine !== '' && $theirs !== '' && (str_contains($theirs, $mine) || str_contains($mine, $theirs))) {
                    return true;
                }

                return $mySize !== null && $size((string) $r->name) === $mySize
                    && array_intersect($myWords, $words((string) $r->name)) !== [];
            })
            ->take(2)
            ->map(fn ($r) => ['code' => (string) $r->code, 'name' => (string) $r->name, 'qty' => Decimal::strip((string) $r->qty)])
            ->values()
            ->all();
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

        return $this->printRun(collect([$batch->id => $shipments]), $scope, $user, $scope === 'courier' ? $courier : null);
    }

    /**
     * Print the labels picked on the day's screen, from any of the day's
     * batches: by courier, then in page order within each file.
     *
     * @param  list<int>  $shipmentIds
     * @return array{shipments: int, pages: int, parts: list<array{file_id: int, pages: list<int>, courier: string|null, shipment_id: int}>}
     */
    public function printSelected(Builder $visible, array $shipmentIds, User $user): array
    {
        $shipments = $visible
            ->whereKey($shipmentIds)
            ->where('status', '<>', ShipmentStatus::Cancelled->value)
            ->whereIn('status', ShipmentStatus::awaitingPacking())
            ->get()
            ->sortBy([
                fn (Shipment $a, Shipment $b) => strcmp((string) $a->courier, (string) $b->courier),
                fn (Shipment $a, Shipment $b) => [$a->label_file_id, $a->pages[0] ?? 0] <=> [$b->label_file_id, $b->pages[0] ?? 0],
            ])
            ->values();

        if ($shipments->isEmpty()) {
            throw new OnlineOrderException('None of those labels can be printed: they are packed, with the courier or cancelled.');
        }

        $couriers = $shipments->pluck('courier')->unique();

        return $this->printRun(
            $shipments->groupBy('label_batch_id'),
            $couriers->count() === 1 ? 'courier' : 'selected',
            $user,
            $couriers->count() === 1 ? $couriers->first() : null,
            $shipments,
        );
    }

    /**
     * Mark the labels printed, log the run against each batch, and say which
     * pages of which files to send to the printer.
     *
     * @param  Collection<int|string, Collection<int, Shipment>>  $byBatch
     * @param  Collection<int, Shipment>|null  $ordered
     * @return array{shipments: int, pages: int, parts: list<array{file_id: int, pages: list<int>, courier: string|null, shipment_id: int}>}
     */
    private function printRun(Collection $byBatch, string $scope, User $user, ?string $courier, ?Collection $ordered = null): array
    {
        $shipments = $ordered ?? $byBatch->flatten(1)->values();

        // Nothing is marked printed if a label's PDF is not there to print.
        $missing = LabelFile::query()
            ->with('batch:id,number')
            ->whereKey($shipments->pluck('label_file_id')->merge($shipments->pluck('invoice_file_id')->filter())->unique())
            ->get()
            ->reject(fn (LabelFile $f) => $this->files->has($f));

        if ($missing->isNotEmpty()) {
            throw new OnlineOrderException(sprintf(
                'The label file%s %s %s missing from the server. Upload the same PDF again (Online orders → Upload labels) to put it back — no orders are added twice — then print.',
                $missing->count() === 1 ? '' : 's',
                $missing->map(fn (LabelFile $f) => "{$f->original_name} ({$f->batch?->number})")->implode(', '),
                $missing->count() === 1 ? 'is' : 'are',
            ));
        }

        DB::transaction(function () use ($byBatch, $scope, $courier, $user): void {
            $now = now();

            foreach ($byBatch as $batchId => $shipments) {
                foreach ($shipments as $shipment) {
                    $shipment->fill([
                        'status' => $shipment->status === ShipmentStatus::Uploaded ? ShipmentStatus::Printed : $shipment->status,
                        'printed_at' => $shipment->printed_at ?? $now,
                        'printed_by' => $shipment->printed_by ?? $user->id,
                        'print_count' => $shipment->print_count + 1,
                    ])->save();
                }

                LabelPrint::create([
                    'label_batch_id' => $batchId,
                    'printed_by' => $user->id,
                    'scope' => $scope,
                    'courier' => $courier,
                    'shipment_count' => $shipments->count(),
                    'page_count' => $shipments->sum(fn (Shipment $s) => count($s->pages)),
                ]);
            }
        });

        // Stock may have come in since the upload.
        $shipments->filter(fn (Shipment $s) => in_array($s->stock_state, [StockState::Short, StockState::Unmapped], true))
            ->each(fn (Shipment $s) => $this->hold($s));

        // Each label, then its invoice when it came in a separate file (Myntra).
        $parts = $shipments->flatMap(fn (Shipment $s) => array_values(array_filter([
            ['shipment_id' => $s->id, 'file_id' => $s->label_file_id, 'pages' => array_values($s->pages), 'courier' => $s->courier],
            $s->invoice_file_id !== null && $s->invoice_pages
                ? ['shipment_id' => $s->id, 'file_id' => $s->invoice_file_id, 'pages' => array_values($s->invoice_pages), 'courier' => $s->courier]
                : null,
        ])))->values();

        return [
            'shipments' => $shipments->count(),
            'pages' => $parts->sum(fn (array $p) => count($p['pages'])),
            'parts' => $parts->all(),
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
            ->with(['lines.item:id,code,name', 'picks.item:id,code,name', 'picks.line:id,seller_sku', 'marketplace:id,name', 'brand:id,name', 'warehouse:id,code,name,facility_id', 'packer:id,name'])
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
            $shipment = Shipment::query()->lockForUpdate()->with(['lines', 'picks.item.stockUom', 'brand', 'warehouse', 'marketplace'])->findOrFail($shipment->id);

            if ($shipment->status === ShipmentStatus::Cancelled) {
                throw new OnlineOrderException("Do not pack this parcel: order {$shipment->order_number} was cancelled".($shipment->cancel_reason ? " ({$shipment->cancel_reason})" : '').'.');
            }

            if ($shipment->status === ShipmentStatus::Returned) {
                throw new OnlineOrderException('Do not pack this parcel: it already went out and came back as a return.');
            }

            if ($shipment->status->isPacked()) {
                throw new OnlineOrderException("Already packed{$this->byWhom($shipment)}. Do not pack it twice.");
            }

            if (! $shipment->isMapped()) {
                throw new OnlineOrderException('The ERP does not know which product this label is for yet. Ask the office to map "'.($shipment->lines->first()?->seller_sku ?? 'the SKU').'" first.');
            }

            if ($this->hold($shipment) !== StockState::Reserved) {
                throw new OnlineOrderException(sprintf(
                    'Not enough %s in %s to pack this parcel. Bring stock in first.',
                    $this->shortItems($shipment) ?: 'stock',
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

            return $shipment->refresh()->load(['lines.item:id,code,name', 'picks.item:id,code,name', 'picks.line:id,seller_sku', 'marketplace:id,name', 'brand:id,name', 'packer:id,name']);
        });
    }

    // ---- Split labels ---------------------------------------------------------

    /**
     * Myntra sends a day's courier labels and its tax invoices as two PDFs.
     * A label read alone has the AWB and the buyer but no product; an
     * invoice read alone has the product, the order and the PacketID but
     * no AWB. The two halves of the same order — same buyer name and PIN
     * code, same day, marketplace, brand and store — become one parcel: the
     * label's AWB and pages, the invoice's product, order, PacketID and
     * pages (printed right after the label).
     *
     * @return int the parcels made whole
     */
    public function pairSplitParcels(LabelBatch $batch): int
    {
        $sameDay = LabelBatch::query()->select('id')
            ->where('marketplace_id', $batch->marketplace_id)
            ->where('brand_id', $batch->brand_id)
            ->where('warehouse_id', $batch->warehouse_id)
            ->whereDate('for_date', $batch->for_date->toDateString());

        $halves = fn () => Shipment::query()
            ->whereIn('label_batch_id', $sameDay)
            ->whereIn('status', ShipmentStatus::awaitingPacking())
            ->orderBy('label_file_id')
            ->orderBy('id');

        $labels = $halves()->whereNotNull('awb')->whereDoesntHave('lines')->get();
        $invoices = $halves()->whereNull('awb')->whereHas('lines')->get();

        if ($labels->isEmpty() || $invoices->isEmpty()) {
            $this->markWaitingForInvoice($labels);

            return 0;
        }

        $key = fn (Shipment $s): ?string => ($name = $this->buyerKey($s->customer_name)) === null
            ? null
            : $name.'|'.($s->customer_pincode ?? '');

        $byKey = $invoices->groupBy(fn (Shipment $s) => $key($s) ?? '');
        $labelsByKey = $labels->groupBy(fn (Shipment $s) => $key($s) ?? '');
        $paired = 0;

        foreach ($labelsByKey as $k => $group) {
            $candidates = $byKey->get($k);

            // A label without a PIN code read, or an invoice without one,
            // still pairs on the name alone — only when that is certain.
            if ($candidates === null && $k !== '' && str_ends_with($k, '|')) {
                $candidates = $invoices->filter(fn (Shipment $s) => str_starts_with((string) $key($s), $k));
            }

            if ($k === '' || $candidates === null || $candidates->isEmpty()) {
                continue;
            }

            // The same buyer twice in a day: pair only when the counts agree,
            // in the order the pages came.
            if ($candidates->count() !== $group->count()) {
                continue;
            }

            foreach ($group->values() as $i => $label) {
                $this->mergeHalves($label, $candidates->values()[$i]);
                $invoices = $invoices->reject(fn (Shipment $s) => $s->id === $candidates->values()[$i]->id);
                $paired++;
            }
        }

        // Names are read from pictures, and a letter can come out wrong. A
        // label and an invoice that are the only two left with a PIN code,
        // and whose names all but agree, are the same parcel.
        $invoicesLeft = $halves()->whereNull('awb')->whereHas('lines')->get();

        foreach ($halves()->whereNotNull('awb')->whereDoesntHave('lines')->whereNotNull('customer_pincode')->get()->groupBy('customer_pincode') as $pincode => $group) {
            $match = $invoicesLeft->where('customer_pincode', $pincode);

            if ($group->count() === 1 && $match->count() === 1 && $this->namesAgree($group->first()->customer_name, $match->first()->customer_name)) {
                $this->mergeHalves($group->first(), $match->first());
                $paired++;
            }
        }

        $this->markWaitingForInvoice($halves()->whereNotNull('awb')->whereDoesntHave('lines')->get());

        return $paired;
    }

    /**
     * Two readings of one buyer's name: the same letters, give or take a
     * misread one or two.
     */
    private function namesAgree(?string $a, ?string $b): bool
    {
        $a = $this->buyerKey($a);
        $b = $this->buyerKey($b);

        if ($a === null || $b === null) {
            return false;
        }

        return levenshtein($a, $b) <= max(1, intdiv(min(strlen($a), strlen($b)), 6));
    }

    private function mergeHalves(Shipment $label, Shipment $invoice): void
    {
        DB::transaction(function () use ($label, $invoice): void {
            $label = Shipment::query()->lockForUpdate()->findOrFail($label->id);
            $invoice = Shipment::query()->lockForUpdate()->findOrFail($invoice->id);

            $this->reservations->releaseAllFor($invoice);
            $invoice->picks()->delete();
            $invoice->lines()->update(['shipment_id' => $label->id]);

            $label->fill([
                'order_number' => $label->order_number ?? $invoice->order_number,
                'alt_code' => $label->alt_code ?? $invoice->alt_code,
                'invoice_number' => $label->invoice_number ?? $invoice->invoice_number,
                'invoice_date' => $label->invoice_date ?? $invoice->invoice_date,
                'seller_gstin' => $label->seller_gstin ?? $invoice->seller_gstin,
                'customer_state' => $label->customer_state ?? $invoice->customer_state,
                'customer_pincode' => $label->customer_pincode ?? $invoice->customer_pincode,
                'payable_amount' => $label->payment_mode === PaymentMode::Cod ? $label->payable_amount : ($invoice->payable_amount ?? $label->payable_amount),
                'invoice_file_id' => $invoice->label_file_id,
                'invoice_pages' => $invoice->pages,
                'warnings' => array_values(array_filter(
                    array_merge($label->warnings ?? [], $invoice->warnings ?? []),
                    // Each half fills the other's gaps: the product, the AWB.
                    fn (string $w) => ! str_starts_with($w, self::WAITING_FOR_INVOICE)
                        && ! str_starts_with($w, 'The label does not say what goes in')
                        && ! str_starts_with($w, 'No AWB was read'),
                )) ?: null,
            ])->save();

            $invoice->delete();

            $this->mapLines($label->refresh());
        });

        // The product is known now: hold its stock, as for any new parcel.
        $this->hold($label->refresh());
    }

    private const string WAITING_FOR_INVOICE = 'Waiting for the invoice file';

    /**
     * @param  Collection<int, Shipment>  $labels
     */
    private function markWaitingForInvoice(Collection $labels): void
    {
        $labels->each(function (Shipment $s): void {
            $s->loadMissing('marketplace:id,code');

            if ($s->marketplace?->code !== 'MYNTRA') {
                return;
            }

            $warnings = $s->warnings ?? [];

            if (collect($warnings)->contains(fn (string $w) => str_starts_with($w, self::WAITING_FOR_INVOICE))) {
                return;
            }

            $warnings[] = self::WAITING_FOR_INVOICE.': Myntra sends the product on a separate invoice PDF. Upload it for the same day and this label is completed.';
            $s->forceFill(['warnings' => $warnings])->save();
        });
    }

    private function buyerKey(?string $name): ?string
    {
        $key = (string) preg_replace('/[^a-z]/', '', strtolower((string) $name));

        return $key === '' ? null : $key;
    }

    private function orderTaken(int $marketplaceId, string $orderNumber): bool
    {
        return Shipment::query()
            ->where('marketplace_id', $marketplaceId)
            ->where('order_number', $orderNumber)
            ->where('status', '<>', ShipmentStatus::Cancelled->value)
            ->exists();
    }

    // ---- Scan out -----------------------------------------------------------

    /**
     * The depot's one scan: the goods are in the box, the parcel is packed
     * (stock out) and waits on the courier's pile as Scanned. A parcel
     * already scanned or already dispatched is reported, not scanned twice.
     *
     * @return array{result: 'scanned'|'already'|'dispatched', shipment: Shipment}
     */
    public function scanOut(Shipment $shipment, User $user): array
    {
        return DB::transaction(function () use ($shipment, $user): array {
            $current = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);

            if ($current->status === ShipmentStatus::Packed) {
                return ['result' => 'already', 'shipment' => $this->forScreen($current)];
            }

            if ($current->status === ShipmentStatus::HandedOver) {
                return ['result' => 'dispatched', 'shipment' => $this->forScreen($current)];
            }

            // Refuses cancelled, returned and unmapped parcels, and short stock.
            return ['result' => 'scanned', 'shipment' => $this->forScreen($this->pack($current, $user))];
        });
    }

    /**
     * The courier has left with them: scanned parcels become Dispatched.
     * Anything not scanned is skipped.
     *
     * @param  list<int>  $shipmentIds
     */
    public function dispatch(Builder $visible, array $shipmentIds, User $user): int
    {
        return DB::transaction(function () use ($visible, $shipmentIds, $user): int {
            return $visible
                ->whereKey($shipmentIds)
                ->where('status', ShipmentStatus::Packed->value)
                ->update([
                    'status' => ShipmentStatus::HandedOver->value,
                    'handed_over_at' => now(),
                    'handed_over_by' => $user->id,
                    'updated_at' => now(),
                ]);
        });
    }

    private function forScreen(Shipment $shipment): Shipment
    {
        return $shipment->refresh()->load(['lines.item:id,code,name', 'picks.item:id,code,name', 'picks.line:id,seller_sku', 'marketplace:id,name', 'brand:id,name', 'packer:id,name', 'handedOverBy:id,name']);
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

            // A parcel handed over on a signed sheet has left the building:
            // it comes back as a return. One only scanned out is still on
            // the courier's pile when the courier finds it cancelled.
            if ($shipment->status === ShipmentStatus::HandedOver && $shipment->handover_sheet_id !== null) {
                throw new OnlineOrderException("{$shipment->reference()} is with the courier. It comes back as a return, not a cancellation.");
            }

            if ($shipment->status === ShipmentStatus::Packed || $shipment->status === ShipmentStatus::HandedOver) {
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
        // A label read badly (a re-saved PDF runs its words together) must
        // not stop the whole file: a value too long for its field is left
        // out or cut, and the parcel says so.
        $warnings = $this->parcelWarnings($parcel) ?? [];
        $fit = function (?string $value, int $max, string $what, bool $cut = false) use (&$warnings): ?string {
            if ($value === null || mb_strlen($value) <= $max) {
                return $value;
            }

            $warnings[] = $cut
                ? "The {$what} read from this label was too long and was cut. Check it."
                : "The {$what} read from this label made no sense and was left out. Type it in from the label.";

            return $cut ? mb_substr($value, 0, $max) : null;
        };

        $amount = $parcel->payableAmount !== null && (float) $parcel->payableAmount >= 1e9 ? null : $parcel->payableAmount;

        $shipment = Shipment::create([
            'label_batch_id' => $batch->id,
            'label_file_id' => $file->id,
            'marketplace_id' => $batch->marketplace_id,
            'brand_id' => $batch->brand_id,
            'warehouse_id' => $batch->warehouse_id,
            'pages' => $parcel->pages,
            'awb' => $fit($parcel->awb, 64, 'AWB'),
            'alt_code' => $fit($parcel->altCode, 64, 'second tracking code'),
            'order_number' => $fit($parcel->orderNumber, 64, 'order number'),
            'courier' => $fit($parcel->courier, 64, 'courier'),
            'payment_mode' => $parcel->paymentMode,
            'payable_amount' => $amount,
            'invoice_number' => $fit($parcel->invoiceNumber, 64, 'invoice number'),
            'invoice_date' => $parcel->invoiceDate,
            'customer_name' => $fit($parcel->customerName, 255, 'customer name', cut: true),
            'customer_state' => $fit($parcel->customerState, 64, 'state'),
            'customer_pincode' => $fit($parcel->customerPincode, 10, 'PIN code'),
            'seller_gstin' => $fit($parcel->sellerGstin, 20, 'seller GSTIN'),
            'status' => ShipmentStatus::Uploaded,
            'stock_state' => StockState::Unmapped,
            'extraction' => $parcel->toArray(),
            'warnings' => $warnings === [] ? null : array_values(array_unique($warnings)),
        ]);

        foreach ($parcel->lines as $i => $line) {
            $shipment->lines()->create([
                'line_no' => $i + 1,
                'seller_sku' => Str::limit($line['seller_sku'], 250, ''),
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
     * Match each line to a listing for the brand on this marketplace, and
     * write the parcel's pick list: every product of the listing, times the
     * label's quantity, in stock units.
     */
    private function mapLines(Shipment $shipment): void
    {
        $shipment->loadMissing('lines');

        $listings = MarketplaceListing::query()
            ->where('marketplace_id', $shipment->marketplace_id)
            ->where('brand_id', $shipment->brand_id)
            ->where('is_active', true)
            ->with('components')
            ->get()
            ->keyBy('sku_key');

        $shipment->picks()->delete();

        foreach ($shipment->lines as $line) {
            $listing = $listings->get(MarketplaceListing::keyFor($line->seller_sku));
            $components = $listing?->components ?? collect();
            $single = $components->count() === 1 ? $components->first() : null;

            $line->fill([
                'listing_id' => $listing?->id,
                // Shown on screen for a plain listing; a combo shows its picks.
                'item_id' => $single?->item_id,
                'units' => $single === null ? null : (string) BigDecimal::of($line->quantity)->multipliedBy($single->units_per_order),
            ])->save();

            foreach ($components as $component) {
                $shipment->picks()->create([
                    'shipment_line_id' => $line->id,
                    'item_id' => $component->item_id,
                    'units' => (string) BigDecimal::of($line->quantity)->multipliedBy($component->units_per_order),
                ]);
            }
        }

        $shipment->unsetRelation('lines');
        $shipment->unsetRelation('picks');
    }

    /**
     * The products of a parcel the store cannot cover, by name.
     */
    private function shortItems(Shipment $shipment): string
    {
        $shipment->loadMissing(['picks.item', 'brand']);
        $short = [];

        foreach ($shipment->picks->groupBy('item_id') as $picks) {
            /** @var ShipmentPick $first */
            $first = $picks->first();
            $need = $picks->reduce(fn (BigDecimal $c, ShipmentPick $p) => $c->plus(BigDecimal::of($p->units)), BigDecimal::zero());
            $free = $this->balances->releasableBalances($first->item, [$shipment->warehouse_id], ownerClientId: $shipment->brand->client_id)
                ->reduce(fn (BigDecimal $c, $b) => $c->plus($b->available()), BigDecimal::zero());

            if ($need->isGreaterThan($free)) {
                $short[] = $first->item->name;
            }
        }

        return implode(' and ', $short);
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
