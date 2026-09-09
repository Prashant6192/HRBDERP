<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\DTOs\LedgerLine;
use App\Domain\Inventory\DTOs\LedgerPosting;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Models\InventoryTransaction;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\MasterData\Models\Item;
use App\Domain\Warehousing\Models\Warehouse;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Holds stock for a production order without taking it off the shelf.
 *
 * Reserve on approval, consume on start, release on cancellation. Physical
 * stock is untouched by a reservation; what changes is how much is available
 * to the next person asking.
 *
 * Every method locks the balance rows it touches, so two orders approved at
 * the same moment for the last of a material cannot both be told yes.
 */
class InventoryReservationService
{
    public function __construct(
        private readonly StockBalanceService $balances,
        private readonly InventoryLedgerService $ledger,
    ) {}

    /**
     * Reserve a quantity of an item in a warehouse, drawing on released lots
     * in expiry order. One reservation row is written per lot used.
     *
     * @return Collection<int, StockReservation>
     *
     * @throws InsufficientStockException if the full quantity cannot be held
     */
    public function reserve(
        Model $reservable,
        Item $item,
        Warehouse $warehouse,
        BigDecimal|string|int $quantity,
        ?int $userId = null,
        ?string $notes = null,
    ): Collection {
        $requested = BigDecimal::of($quantity);

        if (! $requested->isPositive()) {
            throw new InvalidArgumentException('A reservation must be for a positive quantity.');
        }

        return DB::transaction(function () use ($reservable, $item, $warehouse, $requested, $userId, $notes): Collection {
            $candidates = $this->balances->releasableBalances($item, [$warehouse->id], lock: true);

            $remaining = $requested;
            $created = collect();

            foreach ($candidates as $balance) {
                $free = $balance->available();

                if (! $free->isPositive()) {
                    continue;
                }

                $take = $free->isLessThan($remaining) ? $free : $remaining;

                $created->push(StockReservation::create([
                    'item_id' => $item->id,
                    'warehouse_id' => $warehouse->id,
                    'lot_id' => $balance->lot_id,
                    'quantity' => (string) $take,
                    'consumed_quantity' => '0',
                    'reservable_type' => $reservable->getMorphClass(),
                    'reservable_id' => $reservable->getKey(),
                    'status' => ReservationStatus::Active,
                    'reserved_by' => $userId,
                    'reserved_at' => now(),
                    'notes' => $notes,
                ]));

                $balance->forceFill(['reserved' => (string) $balance->reserved()->plus($take)])->save();

                $remaining = $remaining->minus($take);

                if ($remaining->isZero()) {
                    break;
                }
            }

            if (! $remaining->isZero()) {
                throw InsufficientStockException::forReservation(
                    $item,
                    $warehouse,
                    $requested,
                    $requested->minus($remaining),
                );
            }

            return $created;
        });
    }

    /**
     * Give back whatever is still held.
     */
    public function release(StockReservation $reservation): void
    {
        DB::transaction(function () use ($reservation): void {
            $reservation = StockReservation::query()->lockForUpdate()->findOrFail($reservation->id);

            if (! $reservation->status->isOpen()) {
                return;
            }

            $outstanding = $reservation->outstanding();

            if ($outstanding->isPositive()) {
                $balance = $this->ledger->lockBalance($reservation->item_id, $reservation->warehouse_id, $reservation->lot_id);
                $balance->forceFill(['reserved' => (string) $balance->reserved()->minus($outstanding)])->save();
            }

            $reservation->forceFill([
                'status' => ReservationStatus::Released,
                'closed_at' => now(),
            ])->save();
        });
    }

    /**
     * Release every open reservation held for something — on cancellation.
     */
    public function releaseAllFor(Model $reservable): int
    {
        $reservations = StockReservation::query()
            ->active()
            ->where('reservable_type', $reservable->getMorphClass())
            ->where('reservable_id', $reservable->getKey())
            ->get();

        foreach ($reservations as $reservation) {
            $this->release($reservation);
        }

        return $reservations->count();
    }

    /**
     * Turn held stock into consumed stock: lower the reservation, then post
     * the consumption to the ledger, in one transaction.
     */
    public function consume(
        StockReservation $reservation,
        BigDecimal|string|int $quantity,
        ?Model $reference = null,
        ?int $userId = null,
        InventoryTransactionType $type = InventoryTransactionType::ProductionConsumption,
    ): InventoryTransaction {
        $quantity = BigDecimal::of($quantity);

        if (! $quantity->isPositive()) {
            throw new InvalidArgumentException('Consumption must be a positive quantity.');
        }

        return DB::transaction(function () use ($reservation, $quantity, $reference, $userId, $type): InventoryTransaction {
            $reservation = StockReservation::query()->lockForUpdate()->findOrFail($reservation->id);

            if (! $reservation->status->isOpen()) {
                throw new InvalidArgumentException("Reservation #{$reservation->id} is {$reservation->status->value} and cannot be consumed.");
            }

            $outstanding = $reservation->outstanding();

            if ($quantity->isGreaterThan($outstanding)) {
                throw new InvalidArgumentException(sprintf(
                    'Cannot consume %s against reservation #%d: only %s is outstanding.',
                    $quantity->strippedOfTrailingZeros(),
                    $reservation->id,
                    $outstanding->strippedOfTrailingZeros(),
                ));
            }

            // The reserved figure comes down first, so that the ledger's own
            // "not below reserved" check sees the stock as free to leave.
            $balance = $this->ledger->lockBalance($reservation->item_id, $reservation->warehouse_id, $reservation->lot_id);
            $balance->forceFill(['reserved' => (string) $balance->reserved()->minus($quantity)])->save();

            $transaction = $this->ledger->post(new LedgerPosting(
                type: $type,
                warehouseId: $reservation->warehouse_id,
                lines: [new LedgerLine(
                    $reservation->item_id,
                    $reservation->warehouse_id,
                    $quantity->negated(),
                    $reservation->lot_id,
                )],
                reference: $reference,
                createdBy: $userId,
            ));

            $consumed = $reservation->consumed()->plus($quantity);
            $fullyConsumed = $consumed->isEqualTo($reservation->quantity());

            $reservation->forceFill([
                'consumed_quantity' => (string) $consumed,
                'status' => $fullyConsumed ? ReservationStatus::Consumed : ReservationStatus::Active,
                'closed_at' => $fullyConsumed ? now() : null,
            ])->save();

            return $transaction;
        });
    }

    /**
     * How much is held for something, across all its reservations.
     */
    public function outstandingFor(Model $reservable, ?Item $item = null): BigDecimal
    {
        $query = StockReservation::query()
            ->active()
            ->where('reservable_type', $reservable->getMorphClass())
            ->where('reservable_id', $reservable->getKey());

        if ($item !== null) {
            $query->where('item_id', $item->id);
        }

        $total = BigDecimal::zero();

        foreach ($query->get() as $reservation) {
            $total = $total->plus($reservation->outstanding());
        }

        return $total;
    }
}
