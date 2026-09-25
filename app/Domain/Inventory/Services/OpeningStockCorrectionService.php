<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\DTOs\LedgerLine;
use App\Domain\Inventory\DTOs\LedgerPosting;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\Inventory\Exceptions\OpeningStockException;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\InventoryTransaction;
use App\Domain\Inventory\Models\InventoryTransactionLine;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Changing or removing a line of opening stock that was booked wrongly.
 *
 * The ledger is never edited. A correction is its own posting
 * (OPENING_CORRECTION) against the same batch, carrying the reason, so the
 * history shows the first figure, the correction and who made it. It is
 * only allowed while the batch has not moved since it was booked: once any
 * of it has been issued, transferred, packed or reserved, the normal stock
 * tools take over.
 */
class OpeningStockCorrectionService
{
    private const OPENING_TYPES = [
        InventoryTransactionType::OpeningBalance,
        InventoryTransactionType::OpeningCorrection,
    ];

    public function __construct(
        private readonly InventoryLedgerService $ledger,
    ) {}

    /**
     * Every batch opening stock booked at a facility, newest first, with
     * what it holds now and whether it can still be corrected.
     *
     * @param  list<int>|null  $storeIds  limit to these stores
     * @return Collection<int, array<string, mixed>>
     */
    public function entries(Facility $facility, ?array $storeIds = null, int $limit = 500): Collection
    {
        $lines = InventoryTransactionLine::query()
            ->select('inventory_transaction_lines.*')
            ->join('inventory_transactions', 'inventory_transactions.id', '=', 'inventory_transaction_lines.inventory_transaction_id')
            ->join('warehouses', 'warehouses.id', '=', 'inventory_transaction_lines.warehouse_id')
            ->where('inventory_transactions.type', InventoryTransactionType::OpeningBalance->value)
            ->where('warehouses.facility_id', $facility->id)
            ->when($storeIds !== null, fn ($q) => $q->whereIn('inventory_transaction_lines.warehouse_id', $storeIds))
            ->whereNotNull('inventory_transaction_lines.lot_id')
            ->with([
                'transaction:id,number,transacted_at,created_by',
                'transaction.createdBy:id,name',
            ])
            ->orderByDesc('inventory_transaction_lines.id')
            ->limit($limit)
            ->get();

        if ($lines->isEmpty()) {
            return collect();
        }

        $lotIds = $lines->pluck('lot_id')->unique()->values()->all();
        $lots = InventoryLot::query()->with('item:id,code,name,stock_uom_id', 'item.stockUom:id,code')->findMany($lotIds)->keyBy('id');
        $stores = Warehouse::query()->findMany($lines->pluck('warehouse_id')->unique()->all())->keyBy('id');
        $net = $this->openingNet($lotIds);
        $moved = $this->movedLots($lotIds);
        $reserved = $this->reservedLots($lotIds);

        return $lines->map(function (InventoryTransactionLine $line) use ($lots, $stores, $net, $moved, $reserved): ?array {
            $lot = $lots->get($line->lot_id);
            $store = $stores->get($line->warehouse_id);

            if ($lot === null || $store === null) {
                return null;
            }

            $now = $net[$lot->id] ?? BigDecimal::zero();
            $locked = match (true) {
                $now->isZero() => 'Removed',
                isset($moved[$lot->id]) => 'Already used or moved ('.$moved[$lot->id].')',
                isset($reserved[$lot->id]) => 'Reserved for a production order',
                default => null,
            };

            return [
                'lot_id' => $lot->id,
                'store_id' => $store->id,
                'store' => $store->name,
                'item' => $lot->item?->name,
                'item_code' => $lot->item?->code,
                'uom' => $lot->item?->stockUom?->code,
                'batch_number' => $lot->batch_number,
                'manufactured_at' => $lot->manufactured_at?->toDateString(),
                'expiry_at' => $lot->expiry_at?->toDateString(),
                'unit_cost' => $lot->unit_cost !== null ? $this->strip((string) $lot->unit_cost) : null,
                'booked_quantity' => $this->strip((string) $line->quantity),
                'quantity' => $this->strip((string) $now),
                'corrected' => ! $now->isEqualTo(BigDecimal::of($line->quantity)),
                'number' => $line->transaction?->number,
                'booked_at' => $line->transaction?->transacted_at?->toIso8601String(),
                'booked_by' => $line->transaction?->createdBy?->name,
                'locked' => $locked,
            ];
        })->filter()->values();
    }

    /**
     * Take the whole batch back out, as if it had never been booked.
     */
    public function remove(InventoryLot $lot, string $reason, User $user): InventoryTransaction
    {
        return DB::transaction(function () use ($lot, $reason, $user): InventoryTransaction {
            [$lot, $store, $net] = $this->lockCorrectable($lot, $reason);

            $posting = $this->ledger->post(new LedgerPosting(
                type: InventoryTransactionType::OpeningCorrection,
                warehouseId: $store->id,
                lines: [new LedgerLine($lot->item_id, $store->id, $net->negated(), $lot->id, null, $lot->unit_cost)],
                reference: $lot,
                reason: 'Opening stock removed: '.trim($reason),
                createdBy: $user->id,
            ));

            $lot->forceFill([
                'notes' => trim(($lot->notes ?? '').' Opening stock removed by '.$user->name.': '.trim($reason)),
            ])->save();

            return $posting;
        });
    }

    /**
     * Correct the quantity, batch number, dates or rate of a batch.
     *
     * @param  array{quantity: string, batch_number?: string|null, manufactured_at?: string|null, expiry_at?: string|null, unit_cost?: string|null}  $data
     */
    public function change(InventoryLot $lot, array $data, string $reason, User $user): ?InventoryTransaction
    {
        return DB::transaction(function () use ($lot, $data, $reason, $user): ?InventoryTransaction {
            [$lot, $store, $net] = $this->lockCorrectable($lot, $reason);

            $quantity = BigDecimal::of($data['quantity']);

            if (! $quantity->isPositive()) {
                throw new OpeningStockException('The quantity must be greater than zero. To take the batch out altogether, remove it.');
            }

            $oldCost = $lot->unit_cost !== null ? BigDecimal::of($lot->unit_cost) : null;
            $newCost = isset($data['unit_cost']) && $data['unit_cost'] !== null && $data['unit_cost'] !== '' ? BigDecimal::of($data['unit_cost']) : null;

            if ($newCost !== null && $newCost->isNegative()) {
                throw new OpeningStockException('The rate cannot be negative.');
            }

            $batch = trim((string) ($data['batch_number'] ?? ''));
            $batch = $batch === '' ? $lot->batch_number : $batch;

            if ($batch !== $lot->batch_number
                && InventoryLot::query()->where('item_id', $lot->item_id)->where('batch_number', $batch)->whereKeyNot($lot->id)->exists()) {
                throw new OpeningStockException("This product already has a batch {$batch}.");
            }

            $manufacturedAt = ! empty($data['manufactured_at']) ? CarbonImmutable::parse($data['manufactured_at'])->toDateString() : null;
            $expiryAt = ! empty($data['expiry_at']) ? CarbonImmutable::parse($data['expiry_at'])->toDateString() : null;

            if ($manufacturedAt !== null && $expiryAt !== null && $expiryAt < $manufacturedAt) {
                throw new OpeningStockException('The expiry date is before the manufacturing date.');
            }

            $costChanged = ! ($oldCost === null && $newCost === null)
                && ($oldCost === null || $newCost === null || ! $oldCost->isEqualTo($newCost));
            $posting = null;

            // The ledger carries a rate per line, so a new rate takes the old
            // figure out at the old rate and puts the new one in at the new.
            if (! $quantity->isEqualTo($net) || $costChanged) {
                $lines = $costChanged
                    ? [
                        new LedgerLine($lot->item_id, $store->id, $net->negated(), $lot->id, null, $oldCost),
                        new LedgerLine($lot->item_id, $store->id, $quantity, $lot->id, null, $newCost),
                    ]
                    : [new LedgerLine($lot->item_id, $store->id, $quantity->minus($net), $lot->id, null, $oldCost)];

                $posting = $this->ledger->post(new LedgerPosting(
                    type: InventoryTransactionType::OpeningCorrection,
                    warehouseId: $store->id,
                    lines: $lines,
                    reference: $lot,
                    reason: "Opening stock corrected from {$this->strip((string) $net)} to {$this->strip((string) $quantity)}: ".trim($reason),
                    createdBy: $user->id,
                ));
            }

            $lot->forceFill([
                'batch_number' => $batch,
                'manufactured_at' => $manufacturedAt,
                'expiry_at' => $expiryAt,
                'unit_cost' => $newCost?->__toString(),
                'initial_quantity' => $quantity->__toString(),
            ])->save();

            return $posting;
        });
    }

    /**
     * The store a batch was booked into as opening stock, if it was.
     */
    public function openingStore(InventoryLot $lot): ?Warehouse
    {
        $line = InventoryTransactionLine::query()
            ->where('lot_id', $lot->id)
            ->whereHas('transaction', fn ($q) => $q->where('type', InventoryTransactionType::OpeningBalance->value))
            ->first();

        return $line ? Warehouse::query()->with('facility')->find($line->warehouse_id) : null;
    }

    /**
     * @return array{0: InventoryLot, 1: Warehouse, 2: BigDecimal}
     */
    private function lockCorrectable(InventoryLot $lot, string $reason): array
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw new OpeningStockException('Say why the opening stock is being corrected (at least a few words).');
        }

        $lot = InventoryLot::query()->lockForUpdate()->findOrFail($lot->id);

        $store = $this->openingStore($lot);

        if ($store === null) {
            throw new OpeningStockException("Batch {$lot->batch_number} was not booked as opening stock.");
        }

        if ($store->facility !== null && ! $store->facility->opening_stock_enabled) {
            throw new OpeningStockException("Opening stock is closed for {$store->facility->name}. Re-open it in the facility settings to correct it.");
        }

        $net = $this->openingNet([$lot->id])[$lot->id] ?? BigDecimal::zero();

        if ($net->isZero()) {
            throw new OpeningStockException("Batch {$lot->batch_number} has already been removed.");
        }

        $moved = $this->movedLots([$lot->id]);

        if (isset($moved[$lot->id])) {
            throw new OpeningStockException("Batch {$lot->batch_number} has already been used or moved ({$moved[$lot->id]}), so it can no longer be corrected here. Use a stock adjustment instead.");
        }

        if (isset($this->reservedLots([$lot->id])[$lot->id])) {
            throw new OpeningStockException("Batch {$lot->batch_number} is reserved for a production order. Release the reservation first.");
        }

        return [$lot, $store, $net];
    }

    /**
     * @param  list<int>  $lotIds
     * @return array<int, BigDecimal>
     */
    private function openingNet(array $lotIds): array
    {
        return InventoryTransactionLine::query()
            ->join('inventory_transactions', 'inventory_transactions.id', '=', 'inventory_transaction_lines.inventory_transaction_id')
            ->whereIn('inventory_transaction_lines.lot_id', $lotIds)
            ->whereIn('inventory_transactions.type', array_map(fn ($t) => $t->value, self::OPENING_TYPES))
            ->groupBy('inventory_transaction_lines.lot_id')
            ->selectRaw('inventory_transaction_lines.lot_id, SUM(inventory_transaction_lines.quantity) AS net')
            ->pluck('net', 'lot_id')
            ->map(fn ($n) => BigDecimal::of((string) $n))
            ->all();
    }

    /**
     * Batches with any movement other than opening stock and its corrections.
     *
     * @param  list<int>  $lotIds
     * @return array<int, string> lot id => what moved it
     */
    private function movedLots(array $lotIds): array
    {
        return InventoryTransactionLine::query()
            ->join('inventory_transactions', 'inventory_transactions.id', '=', 'inventory_transaction_lines.inventory_transaction_id')
            ->whereIn('inventory_transaction_lines.lot_id', $lotIds)
            ->whereNotIn('inventory_transactions.type', array_map(fn ($t) => $t->value, self::OPENING_TYPES))
            ->orderBy('inventory_transactions.id')
            ->get(['inventory_transaction_lines.lot_id', 'inventory_transactions.type'])
            ->groupBy('lot_id')
            ->map(fn (Collection $rows) => InventoryTransactionType::from($rows->first()->type)->label())
            ->all();
    }

    /**
     * @param  list<int>  $lotIds
     * @return array<int, true>
     */
    private function reservedLots(array $lotIds): array
    {
        return StockReservation::query()
            ->whereIn('lot_id', $lotIds)
            ->where('status', ReservationStatus::Active->value)
            ->pluck('lot_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    private function strip(string $number): string
    {
        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }
}
