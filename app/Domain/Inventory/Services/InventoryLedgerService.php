<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\DTOs\LedgerLine;
use App\Domain\Inventory\DTOs\LedgerPosting;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Exceptions\LedgerIntegrityException;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\InventoryTransaction;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\MasterData\Models\Item;
use App\Domain\Warehousing\Models\Warehouse;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only way stock moves.
 *
 * A posting is written and its balances updated inside one database
 * transaction, with each balance row locked FOR UPDATE before it is read.
 * If any line would take stock negative, or below what is already reserved,
 * the whole posting is rolled back — there are no half-applied movements.
 *
 * Quantities arrive already in the item's stock unit. Nothing here converts.
 */
class InventoryLedgerService
{
    public function __construct(private readonly SequenceService $sequences) {}

    public function post(LedgerPosting $posting): InventoryTransaction
    {
        $this->assertWellFormed($posting);

        return DB::transaction(function () use ($posting): InventoryTransaction {
            $transactedAt = $posting->transactedAt ?? now();

            $transaction = InventoryTransaction::create([
                'number' => $this->sequences->nextNumber('IT', $transactedAt->format('ym'), 6),
                'type' => $posting->type,
                'warehouse_id' => $posting->warehouseId,
                'counterpart_warehouse_id' => $posting->counterpartWarehouseId,
                'reference_type' => $posting->reference?->getMorphClass(),
                'reference_id' => $posting->reference?->getKey(),
                'transacted_at' => $transactedAt,
                'reason' => $posting->reason,
                'created_by' => $posting->createdBy,
            ]);

            foreach ($posting->lines as $line) {
                $this->applyLine($transaction, $line);
            }

            return $transaction;
        });
    }

    // ---- Convenience wrappers ------------------------------------------------

    /**
     * Bring stock in.
     */
    public function receive(
        Item $item,
        Warehouse $warehouse,
        BigDecimal|string|int $quantity,
        ?InventoryLot $lot = null,
        InventoryTransactionType $type = InventoryTransactionType::GrnReceipt,
        ?Model $reference = null,
        ?string $reason = null,
        BigDecimal|string|null $unitCost = null,
        ?int $userId = null,
        ?CarbonInterface $at = null,
    ): InventoryTransaction {
        $quantity = BigDecimal::of($quantity)->abs();

        return $this->post(new LedgerPosting(
            type: $type,
            warehouseId: $warehouse->id,
            lines: [new LedgerLine($item->id, $warehouse->id, $quantity, $lot?->id, null, $unitCost)],
            reference: $reference,
            reason: $reason,
            transactedAt: $at,
            createdBy: $userId,
        ));
    }

    /**
     * Take stock out. The quantity is given as a positive number.
     */
    public function issue(
        Item $item,
        Warehouse $warehouse,
        BigDecimal|string|int $quantity,
        ?InventoryLot $lot = null,
        InventoryTransactionType $type = InventoryTransactionType::StockAdjustmentOut,
        ?Model $reference = null,
        ?string $reason = null,
        ?int $userId = null,
        ?CarbonInterface $at = null,
    ): InventoryTransaction {
        $quantity = BigDecimal::of($quantity)->abs()->negated();

        return $this->post(new LedgerPosting(
            type: $type,
            warehouseId: $warehouse->id,
            lines: [new LedgerLine($item->id, $warehouse->id, $quantity, $lot?->id)],
            reference: $reference,
            reason: $reason,
            transactedAt: $at,
            createdBy: $userId,
        ));
    }

    /**
     * Move stock between warehouses in one atomic posting.
     */
    public function transfer(
        Item $item,
        ?InventoryLot $lot,
        Warehouse $from,
        Warehouse $to,
        BigDecimal|string|int $quantity,
        InventoryTransactionType $type = InventoryTransactionType::StockTransfer,
        ?Model $reference = null,
        ?string $reason = null,
        ?int $userId = null,
        ?CarbonInterface $at = null,
    ): InventoryTransaction {
        $quantity = BigDecimal::of($quantity)->abs();

        if ($from->is($to)) {
            throw new LedgerIntegrityException('A transfer needs two different warehouses.');
        }

        return $this->post(new LedgerPosting(
            type: $type,
            warehouseId: $from->id,
            counterpartWarehouseId: $to->id,
            lines: [
                new LedgerLine($item->id, $from->id, $quantity->negated(), $lot?->id),
                new LedgerLine($item->id, $to->id, $quantity, $lot?->id),
            ],
            reference: $reference,
            reason: $reason,
            transactedAt: $at,
            createdBy: $userId,
        ));
    }

    // ---- Internals -----------------------------------------------------------

    private function applyLine(InventoryTransaction $transaction, LedgerLine $line): void
    {
        $balance = $this->lockBalance($line->itemId, $line->warehouseId, $line->lotId);

        $newOnHand = $balance->onHand()->plus($line->quantity);

        if ($newOnHand->isNegative()) {
            throw InsufficientStockException::forIssue(
                Item::findOrFail($line->itemId),
                Warehouse::findOrFail($line->warehouseId),
                $line->quantity->abs(),
                $balance->onHand(),
            );
        }

        // Stock that is reserved for a production order is not free to leave
        // by any other door. Consumption against the reservation lowers the
        // reserved figure first, through InventoryReservationService.
        if ($newOnHand->isLessThan($balance->reserved())) {
            throw InsufficientStockException::reservedStock(
                Item::findOrFail($line->itemId),
                Warehouse::findOrFail($line->warehouseId),
                $line->quantity->abs(),
                $balance->available(),
            );
        }

        $transaction->lines()->create([
            'item_id' => $line->itemId,
            'lot_id' => $line->lotId,
            'warehouse_id' => $line->warehouseId,
            'location_id' => $line->locationId,
            'quantity' => (string) $line->quantity,
            'unit_cost' => $line->unitCost === null ? null : (string) BigDecimal::of($line->unitCost),
        ]);

        $balance->forceFill(['on_hand' => (string) $newOnHand])->save();
    }

    /**
     * Fetch the balance row for a stock position, creating it if this is the
     * first movement, and lock it for the rest of the transaction.
     *
     * The insert is ON CONFLICT DO NOTHING, so two first movements racing for
     * the same position do not fail on the unique index; whichever loses the
     * insert simply locks the row the winner created.
     */
    public function lockBalance(int $itemId, int $warehouseId, ?int $lotId): StockBalance
    {
        StockBalance::query()->insertOrIgnore([
            'item_id' => $itemId,
            'warehouse_id' => $warehouseId,
            'lot_id' => $lotId,
            'on_hand' => 0,
            'reserved' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return StockBalance::query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->where('lot_id', $lotId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertWellFormed(LedgerPosting $posting): void
    {
        if ($posting->lines === []) {
            throw new LedgerIntegrityException('A posting needs at least one line.');
        }

        $direction = $posting->type->direction();
        $netByPosition = [];

        foreach ($posting->lines as $line) {
            if ($line->quantity->isZero()) {
                throw new LedgerIntegrityException('A ledger line cannot have a zero quantity.');
            }

            if ($direction === 'in' && $line->quantity->isNegative()) {
                throw new LedgerIntegrityException("A {$posting->type->label()} cannot remove stock.");
            }

            if ($direction === 'out' && $line->quantity->isPositive()) {
                throw new LedgerIntegrityException("A {$posting->type->label()} cannot add stock.");
            }

            $key = $line->itemId.':'.($line->lotId ?? 'none');
            $netByPosition[$key] = ($netByPosition[$key] ?? BigDecimal::zero())->plus($line->quantity);
        }

        // A transfer changes where stock is, never how much there is.
        if ($direction === 'both') {
            foreach ($netByPosition as $key => $net) {
                if (! $net->isZero()) {
                    throw new LedgerIntegrityException(
                        "A {$posting->type->label()} must balance to zero per item and lot; [{$key}] nets to {$net}."
                    );
                }
            }
        }
    }
}
