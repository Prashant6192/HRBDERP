<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\DTOs\LedgerLine;
use App\Domain\Inventory\DTOs\LedgerPosting;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Exceptions\ScanInException;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\InventoryTransaction;
use App\Domain\Marketplace\Enums\ShipmentStatus;
use App\Domain\Marketplace\Enums\StockState;
use App\Domain\Marketplace\Models\Shipment;
use App\Domain\MasterData\Models\Item;
use App\Domain\MasterData\Models\Product;
use App\Domain\MasterData\Services\ProductPackService;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Finished goods scanned onto the depot's shelf from the Stock screen.
 *
 * Each scan names a product, single or a pack of two; the person gives the
 * day it came in and, if known, its manufacturing and expiry dates. Booking
 * makes one batch per product and dates and posts the receipt on the day
 * it came in, so stock that arrived last week lands last week. A barcode
 * met for the first time is kept on the product it was matched to.
 */
class ScanInService
{
    public function __construct(
        private readonly InventoryLedgerService $ledger,
        private readonly BatchNumberGenerator $batchNumbers,
        private readonly ProductPackService $packs,
    ) {}

    /**
     * The product a barcode is on: the single it names and how many of it
     * the barcode stands for (a pack's own barcode stands for its pack).
     *
     * @return array{product_id: int, pack: int|null, name: string, code: string}|null
     */
    public function findBarcode(string $code): ?array
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        $product = Product::query()->where('is_active', true)->where('barcode', $code)->orderBy('id')->first()
            ?? Product::query()->where('is_active', true)->where('code', $code)->first();

        if ($product === null) {
            return null;
        }

        $single = $product->pack_of_item_id !== null ? Product::query()->find($product->pack_of_item_id) : $product;

        if ($single === null) {
            return null;
        }

        // A pack's own barcode says which pack; a single's leaves the
        // choice to the screen (Single or Pack of 2).
        return [
            'product_id' => $single->id,
            'pack' => $product->pack_quantity,
            'name' => $single->name,
            'code' => $single->code,
        ];
    }

    /**
     * Book the scanned stock in.
     *
     * @param  list<array{product_id: int, pack: int, quantity: int, came_in: string, manufactured_at?: string|null, expiry_at?: string|null, barcode?: string|null}>  $lines
     * @return array{pieces: int, transactions: list<InventoryTransaction>, packs_added: list<string>, rehold: int}
     *
     * @throws ScanInException
     */
    public function book(Warehouse $store, array $lines, User $user): array
    {
        if ($lines === []) {
            throw new ScanInException('Scan at least one product.');
        }

        if (! $store->is_active || $store->is_quarantine || ! in_array($store->type, [WarehouseType::FinishedGoods, WarehouseType::Marketplace], true)) {
            throw new ScanInException("{$store->name} does not take finished goods scanned in.");
        }

        $today = CarbonImmutable::today((string) config('erp.company.timezone', 'Asia/Kolkata'));
        $packsAdded = [];
        // Parcels short of stock are held the moment it lands (the ledger
        // says so); this counts how many it served.
        $wasShort = $this->shortParcels($store);

        $result = DB::transaction(function () use ($store, $lines, $user, $today, &$packsAdded): array {
            /** @var array<string, array{item: Item, quantity: int, came_in: CarbonImmutable, manufactured_at: ?CarbonImmutable, expiry_at: ?CarbonImmutable}> $merged */
            $merged = [];

            foreach ($lines as $i => $line) {
                $at = 'Line '.($i + 1);
                $single = Product::query()->whereNull('pack_of_item_id')->find((int) $line['product_id']);

                if ($single === null || ! $single->is_active) {
                    throw new ScanInException("{$at}: choose a product that is on file and active.");
                }

                $pack = max(1, (int) $line['pack']);
                $quantity = (int) $line['quantity'];

                if ($quantity < 1) {
                    throw new ScanInException("{$at}: the quantity of {$single->name} must be at least one.");
                }

                $cameIn = $this->date($line['came_in'] ?? null, "{$at}: the date it came in");

                if ($cameIn === null || $cameIn->greaterThan($today)) {
                    throw new ScanInException("{$at}: the date {$single->name} came in cannot be blank or later than today.");
                }

                $made = $this->date($line['manufactured_at'] ?? null, "{$at}: the manufacturing date");
                $expiry = $this->date($line['expiry_at'] ?? null, "{$at}: the expiry date");

                if ($made !== null && $made->greaterThan($cameIn)) {
                    throw new ScanInException("{$at}: {$single->name} cannot be made after the day it came in.");
                }

                if ($made !== null && $expiry !== null && $expiry->lessThanOrEqualTo($made)) {
                    throw new ScanInException("{$at}: the expiry date of {$single->name} must be after its manufacturing date.");
                }

                $item = $single;

                if ($pack > 1) {
                    $existed = $single->packs()->where('pack_quantity', $pack)->exists();
                    $item = $this->packs->packOf($single, $pack, $user->id);

                    if (! $existed) {
                        $packsAdded[] = "{$item->name} ({$item->code})";
                    }
                }

                $expiry ??= $made !== null && $item->shelf_life_days ? $made->addDays((int) $item->shelf_life_days) : null;
                $this->learnBarcode($line['barcode'] ?? null, $single, $item->is($single) ? null : $item);

                $key = implode('|', [$item->id, $cameIn->toDateString(), $made?->toDateString(), $expiry?->toDateString()]);
                $merged[$key] ??= ['item' => $item, 'quantity' => 0, 'came_in' => $cameIn, 'manufactured_at' => $made, 'expiry_at' => $expiry];
                $merged[$key]['quantity'] += $quantity;
            }

            $transactions = [];
            $pieces = 0;

            // One receipt per day it came in, dated that day.
            foreach (collect($merged)->groupBy(fn (array $m) => $m['came_in']->toDateString())->sortKeys() as $day => $group) {
                $ledgerLines = [];

                foreach ($group as $m) {
                    $lot = InventoryLot::create([
                        'item_id' => $m['item']->id,
                        'batch_number' => $this->batchNumbers->generate($m['item'], $m['manufactured_at'] ?? $m['came_in']),
                        'manufactured_at' => $m['manufactured_at']?->toDateString(),
                        'received_at' => $m['came_in']->toDateString(),
                        'expiry_at' => $m['expiry_at']?->toDateString(),
                        // Finished goods come from the factory already passed.
                        'qc_status' => LotQcStatus::Approved,
                        'qc_decided_at' => now(),
                        'qc_decided_by' => $user->id,
                        'initial_quantity' => (string) $m['quantity'],
                        'notes' => "Scanned in at {$store->name}.",
                        'created_by' => $user->id,
                    ]);

                    $ledgerLines[] = new LedgerLine($m['item']->id, $store->id, BigDecimal::of($m['quantity']), $lot->id);
                    $pieces += $m['quantity'];
                }

                // The day it came in, as the company's clock has it.
                $date = CarbonImmutable::parse($day, (string) config('erp.company.timezone', 'Asia/Kolkata'));
                $transactions[] = $this->ledger->post(new LedgerPosting(
                    type: InventoryTransactionType::ScanReceipt,
                    warehouseId: $store->id,
                    lines: $ledgerLines,
                    reason: "Scanned in at {$store->name}, came in {$date->format('j M Y')}.",
                    transactedAt: $date->isSameDay(now($date->timezone)) ? now() : $date->setTime(18, 0)->utc(),
                    createdBy: $user->id,
                ));
            }

            return ['pieces' => $pieces, 'transactions' => $transactions];
        });

        $served = $wasShort === [] ? 0 : Shipment::query()->whereKey($wasShort)->where('stock_state', StockState::Reserved->value)->count();

        return [...$result, 'packs_added' => $packsAdded, 'rehold' => $served];
    }

    /**
     * The parcels in this store still waiting on stock.
     *
     * @return list<int>
     */
    private function shortParcels(Warehouse $store): array
    {
        return Shipment::query()
            ->where('warehouse_id', $store->id)
            ->whereIn('status', ShipmentStatus::awaitingPacking())
            ->where('stock_state', StockState::Short->value)
            ->pluck('id')
            ->all();
    }

    /**
     * Keep a barcode met for the first time: on the single product when it
     * has none (a bottle's barcode), else on the pack it was scanned as (a
     * pack with its own barcode). A barcode already on file stays where it
     * is.
     */
    private function learnBarcode(?string $barcode, Product $single, ?Item $pack): void
    {
        $barcode = trim((string) $barcode);

        if ($barcode === '' || mb_strlen($barcode) > 64 || Item::query()->where('barcode', $barcode)->exists()) {
            return;
        }

        $target = $single->barcode === null ? $single : ($pack !== null && $pack->barcode === null ? $pack : null);
        $target?->forceFill(['barcode' => $barcode])->save();
    }

    private function date(?string $value, string $what): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, (string) config('erp.company.timezone', 'Asia/Kolkata'))->startOfDay();
        } catch (Throwable) {
            throw new ScanInException("{$what} is not a date.");
        }
    }
}
