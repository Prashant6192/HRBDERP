<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\DTOs\LedgerLine;
use App\Domain\Inventory\DTOs\LedgerPosting;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Exceptions\StockTransferException;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\StockTransferLine;
use App\Domain\MasterData\Models\Item;
use App\Domain\Measurement\Exceptions\IncompatibleUnitsException;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Measurement\Services\UnitConversionService;
use App\Domain\Quality\Models\QcInspection;
use App\Domain\Warehousing\Enums\FacilityCapability;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Domain\Warehousing\Services\WarehouseResolver;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Moving stock between facilities.
 *
 *   draft → requested → approved (held at source) → packed → dispatched
 *   → in transit → received / partially received / received with discrepancy
 *
 * On dispatch the stock leaves the source store for the system's in-transit
 * position, lot by lot. On receipt it moves from there into the destination
 * store — or the destination facility's quarantine when an inspection was
 * asked for. Nothing appears at the destination before receipt, and every
 * leg is a ledger posting against the transfer.
 */
class StockTransferService
{
    public function __construct(
        private readonly SequenceService $sequences,
        private readonly InventoryLedgerService $ledger,
        private readonly InventoryReservationService $reservations,
        private readonly StockBalanceService $balances,
        private readonly UnitConversionService $conversions,
        private readonly WarehouseResolver $warehouses,
    ) {}

    /**
     * @param  array{source_warehouse_id: int, destination_warehouse_id: int, requires_inspection?: bool, expected_at?: string|null, reason?: string|null, notes?: string|null}  $attributes
     * @param  list<array{item_id: int, quantity: string, uom_id?: int|null, lot_id?: int|null}>  $lines
     */
    public function create(array $attributes, array $lines, ?int $userId): StockTransfer
    {
        if ($lines === []) {
            throw new StockTransferException('Add at least one item to transfer.');
        }

        return DB::transaction(function () use ($attributes, $lines, $userId): StockTransfer {
            $source = Warehouse::query()->with('facility')->findOrFail($attributes['source_warehouse_id']);
            $destination = Warehouse::query()->with('facility')->findOrFail($attributes['destination_warehouse_id']);

            $this->assertUsable($source, 'source');
            $this->assertUsable($destination, 'destination');

            if ($source->id === $destination->id) {
                throw new StockTransferException('The source and destination stores must differ.');
            }

            if ($source->facility_id === $destination->facility_id) {
                throw new StockTransferException('Both stores are at the same facility. Use a stock adjustment or an internal move for that.');
            }

            if (! $destination->facility->can(FacilityCapability::Store) || ! $destination->facility->can(FacilityCapability::Receive)) {
                throw new StockTransferException("{$destination->facility->name} cannot receive stock: enable storage and receiving on the facility first.");
            }

            $transfer = StockTransfer::create([
                'number' => $this->sequences->nextNumber('TRF', now()->format('ym')),
                'source_facility_id' => $source->facility_id,
                'source_warehouse_id' => $source->id,
                'destination_facility_id' => $destination->facility_id,
                'destination_warehouse_id' => $destination->id,
                'status' => StockTransferStatus::Draft,
                'requires_inspection' => (bool) ($attributes['requires_inspection'] ?? false),
                'expected_at' => $attributes['expected_at'] ?? null,
                'reason' => $attributes['reason'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'requested_by' => $userId,
            ]);

            $lineNo = 0;

            foreach ($lines as $line) {
                $item = Item::query()->with('stockUom')->findOrFail($line['item_id']);
                $quantity = $this->toStockUnit($item, BigDecimal::of($line['quantity']), $line['uom_id'] ?? null);

                if (! $quantity->isPositive()) {
                    throw new StockTransferException("The quantity for {$item->name} must be greater than zero.");
                }

                $transfer->lines()->create([
                    'line_no' => ++$lineNo,
                    'item_id' => $item->id,
                    'lot_id' => $line['lot_id'] ?? null,
                    'uom_id' => $item->stock_uom_id,
                    'quantity_requested' => $quantity->__toString(),
                ]);
            }

            return $transfer->refresh();
        });
    }

    public function request(StockTransfer $transfer, ?int $userId): StockTransfer
    {
        return $this->move($transfer, [StockTransferStatus::Draft], StockTransferStatus::Requested, [
            'requested_by' => $userId, 'requested_at' => now(),
        ]);
    }

    /**
     * Hold the stock at the source. All or nothing: a shortfall on any line
     * leaves nothing held and names what is missing.
     */
    public function approve(StockTransfer $transfer, int $userId): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $userId): StockTransfer {
            $transfer = $this->lock($transfer, [StockTransferStatus::Requested, StockTransferStatus::Draft]);
            $transfer->load(['lines.item.stockUom', 'sourceStore']);

            $short = [];

            foreach ($transfer->lines as $line) {
                $free = $line->lot_id === null
                    ? $this->balances->availableForProduction($line->item, [$transfer->source_warehouse_id])
                    : $this->freeInLot($line->item, $transfer->source_warehouse_id, $line->lot_id);

                if ($free->isLessThan($line->requested())) {
                    $short[] = sprintf('%s (%s %s asked, %s free)', $line->item->name, $line->requested()->strippedOfTrailingZeros(), $line->item->stockUom->code, $free->strippedOfTrailingZeros());
                }
            }

            if ($short !== []) {
                throw new StockTransferException('Quantity not available at '.$transfer->sourceStore->name.': '.implode('; ', $short).'.');
            }

            $lineNo = $transfer->lines->max('line_no');

            foreach ($transfer->lines as $line) {
                try {
                    $held = $this->reservations->reserve($transfer, $line->item, $transfer->sourceStore, $line->requested(), $userId, "Transfer {$transfer->number}", $line->lot_id);
                } catch (InsufficientStockException) {
                    throw new StockTransferException("{$line->item->name} was taken by another order a moment ago. Try again.");
                }

                // One line per batch, so the document says exactly which
                // drums are on the lorry.
                $first = $held->shift();
                $line->forceFill(['lot_id' => $first->lot_id, 'quantity_requested' => $first->quantity])->save();

                foreach ($held as $extra) {
                    /** @var StockReservation $extra */
                    $transfer->lines()->create([
                        'line_no' => ++$lineNo,
                        'item_id' => $line->item_id,
                        'lot_id' => $extra->lot_id,
                        'uom_id' => $line->uom_id,
                        'quantity_requested' => $extra->quantity,
                    ]);
                }
            }

            $transfer->fill(['status' => StockTransferStatus::Approved, 'approved_by' => $userId, 'approved_at' => now()])->save();

            return $transfer->refresh();
        });
    }

    public function reject(StockTransfer $transfer, ?int $userId, ?string $reason = null): StockTransfer
    {
        return $this->move($transfer, [StockTransferStatus::Requested], StockTransferStatus::Rejected, [
            'cancelled_at' => now(),
            'notes' => $this->appendNote($transfer, 'Rejected', $reason),
        ]);
    }

    public function pack(StockTransfer $transfer, ?int $userId): StockTransfer
    {
        return $this->move($transfer, [StockTransferStatus::Approved], StockTransferStatus::Packed);
    }

    /**
     * The lorry leaves: every held lot goes from the source store to the
     * in-transit position in one posting.
     */
    public function dispatch(StockTransfer $transfer, int $userId, ?string $vehicleRef = null): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $userId, $vehicleRef): StockTransfer {
            $transfer = $this->lock($transfer, [StockTransferStatus::Approved, StockTransferStatus::Packed]);
            $transfer->load('lines');
            $transit = $this->warehouses->inTransit();

            $held = $transfer->reservations()->where('status', ReservationStatus::Active->value)->orderBy('id')->get();

            if ($held->isEmpty()) {
                throw new StockTransferException("{$transfer->number} holds no stock to dispatch.");
            }

            $ledgerLines = [];
            $dispatched = [];

            foreach ($held as $reservation) {
                /** @var StockReservation $reservation */
                $quantity = $reservation->outstanding();

                if (! $quantity->isPositive()) {
                    continue;
                }

                // Let go of the hold first so the ledger sees the stock as
                // free to leave, then move it in the same transaction.
                $balance = $this->ledger->lockBalance($reservation->item_id, $reservation->warehouse_id, $reservation->lot_id);
                $balance->forceFill(['reserved' => (string) $balance->reserved()->minus($quantity)])->save();
                $reservation->forceFill(['consumed_quantity' => (string) $reservation->quantity(), 'status' => ReservationStatus::Consumed, 'closed_at' => now()])->save();

                $ledgerLines[] = new LedgerLine($reservation->item_id, $reservation->warehouse_id, $quantity->negated(), $reservation->lot_id);
                $ledgerLines[] = new LedgerLine($reservation->item_id, $transit->id, $quantity, $reservation->lot_id);

                $key = $reservation->item_id.':'.($reservation->lot_id ?? 'none');
                $dispatched[$key] = ($dispatched[$key] ?? BigDecimal::zero())->plus($quantity);
            }

            $this->ledger->post(new LedgerPosting(
                type: InventoryTransactionType::StockTransfer,
                warehouseId: $transfer->source_warehouse_id,
                lines: $ledgerLines,
                counterpartWarehouseId: $transit->id,
                reference: $transfer,
                reason: "Dispatched on {$transfer->number}",
                createdBy: $userId,
            ));

            foreach ($transfer->lines as $line) {
                $key = $line->item_id.':'.($line->lot_id ?? 'none');
                $line->forceFill(['quantity_dispatched' => ($dispatched[$key] ?? BigDecimal::zero())->__toString()])->save();
            }

            $transfer->fill([
                'status' => StockTransferStatus::Dispatched,
                'dispatched_by' => $userId,
                'dispatched_at' => now(),
                'vehicle_ref' => $vehicleRef ?? $transfer->vehicle_ref,
            ])->save();

            return $transfer->refresh();
        });
    }

    public function markInTransit(StockTransfer $transfer): StockTransfer
    {
        return $this->move($transfer, [StockTransferStatus::Dispatched], StockTransferStatus::InTransit);
    }

    /**
     * Book what arrived. Each line may be received in full, in part, or
     * written off (lost or damaged on the way). What is neither received
     * nor written off stays in transit and the transfer stays open.
     *
     * @param  array<int, array{quantity?: string|null, written_off?: string|null, notes?: string|null}>  $received  keyed by line id
     */
    public function receive(StockTransfer $transfer, int $userId, array $received): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $userId, $received): StockTransfer {
            $transfer = $this->lock($transfer, [StockTransferStatus::Dispatched, StockTransferStatus::InTransit, StockTransferStatus::PartiallyReceived]);
            $transfer->load(['lines.item', 'lines.lot', 'destinationStore.facility', 'destinationFacility']);
            $transit = $this->warehouses->inTransit();

            $target = $transfer->destinationStore;
            $inspecting = false;

            if ($transfer->requires_inspection) {
                $quarantine = $transfer->destinationFacility->storeOfKind(WarehouseType::Quarantine);

                if ($quarantine !== null) {
                    $target = $quarantine;
                    $inspecting = true;
                }
            }

            $inLines = [];
            $writeOffs = [];
            $touched = false;

            foreach ($transfer->lines as $line) {
                /** @var StockTransferLine $line */
                $entry = $received[$line->id] ?? null;

                if ($entry === null) {
                    continue;
                }

                $quantity = BigDecimal::of($entry['quantity'] ?? '0');
                $writtenOff = BigDecimal::of($entry['written_off'] ?? '0');

                if ($quantity->isNegative() || $writtenOff->isNegative()) {
                    throw new StockTransferException('Received and written-off quantities cannot be negative.');
                }

                if ($quantity->plus($writtenOff)->isGreaterThan($line->outstanding())) {
                    throw new StockTransferException(sprintf('%s: only %s is still in transit on this line.', $line->item->name, $line->outstanding()->strippedOfTrailingZeros()));
                }

                if ($quantity->isPositive()) {
                    $inLines[] = new LedgerLine($line->item_id, $transit->id, $quantity->negated(), $line->lot_id);
                    $inLines[] = new LedgerLine($line->item_id, $target->id, $quantity, $line->lot_id);
                    $touched = true;
                }

                if ($writtenOff->isPositive()) {
                    $writeOffs[] = new LedgerLine($line->item_id, $transit->id, $writtenOff->negated(), $line->lot_id);
                    $touched = true;
                }

                $line->forceFill([
                    'quantity_received' => $line->received()->plus($quantity)->__toString(),
                    'quantity_written_off' => $line->writtenOff()->plus($writtenOff)->__toString(),
                    'discrepancy_notes' => $entry['notes'] ?? $line->discrepancy_notes,
                ])->save();

                if ($inspecting && $quantity->isPositive() && $line->lot_id !== null) {
                    QcInspection::create([
                        'number' => $this->sequences->nextNumber('QC', now()->format('ym')),
                        'lot_id' => $line->lot_id,
                        'item_id' => $line->item_id,
                        'quantity' => $quantity->__toString(),
                        'status' => LotQcStatus::Pending,
                        'destination_warehouse_id' => $transfer->destination_warehouse_id,
                        'created_by' => $userId,
                    ]);
                }
            }

            if (! $touched) {
                throw new StockTransferException('Nothing was received. Enter a received or written-off quantity on at least one line.');
            }

            if ($inLines !== []) {
                $this->ledger->post(new LedgerPosting(
                    type: InventoryTransactionType::StockTransfer,
                    warehouseId: $transit->id,
                    lines: $inLines,
                    counterpartWarehouseId: $target->id,
                    reference: $transfer,
                    reason: "Received on {$transfer->number}".($inspecting ? ' (held for inspection)' : ''),
                    createdBy: $userId,
                ));
            }

            if ($writeOffs !== []) {
                $this->ledger->post(new LedgerPosting(
                    type: InventoryTransactionType::Damage,
                    warehouseId: $transit->id,
                    lines: $writeOffs,
                    reference: $transfer,
                    reason: "Lost or damaged in transit on {$transfer->number}",
                    createdBy: $userId,
                ));
            }

            $transfer->refresh()->load('lines');
            $outstanding = $transfer->lines->reduce(fn (BigDecimal $c, StockTransferLine $l) => $c->plus($l->outstanding()), BigDecimal::zero());
            $anyWrittenOff = $transfer->lines->contains(fn (StockTransferLine $l) => $l->writtenOff()->isPositive());

            $status = $outstanding->isPositive()
                ? StockTransferStatus::PartiallyReceived
                : ($anyWrittenOff ? StockTransferStatus::Discrepancy : StockTransferStatus::Received);

            $transfer->fill([
                'status' => $status,
                'received_by' => $userId,
                'received_at' => now(),
            ])->save();

            return $transfer->refresh();
        });
    }

    public function cancel(StockTransfer $transfer, ?int $userId, ?string $reason = null): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $reason): StockTransfer {
            $transfer = $this->lock($transfer, [StockTransferStatus::Draft, StockTransferStatus::Requested, StockTransferStatus::Approved, StockTransferStatus::Packed]);

            $this->reservations->releaseAllFor($transfer);

            $transfer->fill([
                'status' => StockTransferStatus::Cancelled,
                'cancelled_at' => now(),
                'notes' => $this->appendNote($transfer, 'Cancelled', $reason),
            ])->save();

            return $transfer->refresh();
        });
    }

    /**
     * Where else the company holds an item that one facility is short of —
     * the basis of "Available at Delhi: 100 → Create Stock Transfer Request".
     *
     * @return Collection<int, array{facility_id: int, facility: string, quantity: BigDecimal}>
     */
    public function availableElsewhere(Item $item, ?Facility $except, ?WarehouseType $kind = null): Collection
    {
        $stores = Warehouse::query()
            ->availableForIssue()
            ->whereNotNull('facility_id')
            ->when($except !== null, fn ($q) => $q->where('facility_id', '!=', $except->id))
            ->when($kind !== null, fn ($q) => $q->where('type', $kind->value))
            ->whereHas('facility', fn ($q) => $q->where('is_active', true))
            ->with('facility:id,name')
            ->get();

        return $stores
            ->groupBy('facility_id')
            ->map(function (Collection $group) use ($item): array {
                /** @var Warehouse $first */
                $first = $group->first();

                return [
                    'facility_id' => $first->facility_id,
                    'facility' => $first->facility->name,
                    'quantity' => $this->balances->availableForProduction($item, $group->pluck('id')->all()),
                ];
            })
            ->filter(fn (array $row) => $row['quantity']->isPositive())
            ->values();
    }

    // ---- Internals ---------------------------------------------------------

    /**
     * @param  list<StockTransferStatus>  $from
     * @param  array<string, mixed>  $extra
     */
    private function move(StockTransfer $transfer, array $from, StockTransferStatus $to, array $extra = []): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $from, $to, $extra): StockTransfer {
            $transfer = $this->lock($transfer, $from);
            $transfer->fill(['status' => $to, ...$extra])->save();

            return $transfer->refresh();
        });
    }

    /**
     * @param  list<StockTransferStatus>  $allowed
     */
    private function lock(StockTransfer $transfer, array $allowed): StockTransfer
    {
        $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->getKey());

        if (! in_array($transfer->status, $allowed, strict: true)) {
            throw new StockTransferException("{$transfer->number} is {$transfer->status->label()} and cannot take that step.");
        }

        return $transfer;
    }

    private function assertUsable(Warehouse $store, string $role): void
    {
        if ($store->is_system || ! $store->is_active) {
            throw new StockTransferException("{$store->name} is not an active store and cannot be the {$role}.");
        }

        if ($store->facility_id === null || $store->facility === null) {
            throw new StockTransferException("{$store->name} does not belong to a facility.");
        }
    }

    private function freeInLot(Item $item, int $warehouseId, int $lotId): BigDecimal
    {
        $balance = StockBalance::query()->where('item_id', $item->id)->where('warehouse_id', $warehouseId)->where('lot_id', $lotId)->first();

        if ($balance === null || $balance->lot === null || ! $balance->lot->isReleasable()) {
            return BigDecimal::zero();
        }

        return $balance->available();
    }

    private function toStockUnit(Item $item, BigDecimal $quantity, ?int $uomId): BigDecimal
    {
        if ($uomId === null || $uomId === $item->stock_uom_id) {
            return $quantity;
        }

        $from = Uom::query()->findOrFail($uomId);

        try {
            return $this->conversions->convert($quantity, $from, $item->stockUom, $item);
        } catch (IncompatibleUnitsException) {
            throw new StockTransferException("Cannot convert {$quantity} {$from->code} of {$item->name} to {$item->stockUom->code}.");
        }
    }

    private function appendNote(StockTransfer $transfer, string $label, ?string $reason): ?string
    {
        if ($reason === null || trim($reason) === '') {
            return $transfer->notes;
        }

        return trim(($transfer->notes ?? '')."\n{$label}: ".trim($reason));
    }
}
