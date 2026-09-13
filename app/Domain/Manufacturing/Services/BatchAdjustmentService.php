<?php

declare(strict_types=1);

namespace App\Domain\Manufacturing\Services;

use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Manufacturing\Enums\ManufacturingOrderStatus;
use App\Domain\Manufacturing\Exceptions\ManufacturingException;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Manufacturing\Models\ManufacturingOrderAdjustment;
use App\Domain\Manufacturing\Models\ManufacturingOrderLine;
use App\Domain\MasterData\Models\Item;
use App\Domain\Planning\Enums\StoreKind;
use App\Domain\Warehousing\Services\WarehouseResolver;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * What a batch gives back or loses after the material was issued.
 *
 * A return goes back to the store through the ledger, against the lot it
 * came from, so it is stock again. Wastage does not: the material is gone;
 * it is recorded so the batch's true consumption, its variance and its
 * cost are known, and so abnormal wastage is an exception, not a mystery.
 */
class BatchAdjustmentService
{
    public function __construct(
        private readonly InventoryLedgerService $ledger,
        private readonly WarehouseResolver $warehouses,
    ) {}

    public function record(ManufacturingOrder $order, string $kind, Item $item, ?InventoryLot $lot, BigDecimal|string $quantity, ?string $reason, ?int $userId): ManufacturingOrderAdjustment
    {
        return DB::transaction(function () use ($order, $kind, $item, $lot, $quantity, $reason, $userId): ManufacturingOrderAdjustment {
            $order = ManufacturingOrder::query()->lockForUpdate()->with(['lines', 'facility'])->findOrFail($order->getKey());
            $quantity = BigDecimal::of($quantity);

            if (! in_array($order->status, [ManufacturingOrderStatus::InProgress, ManufacturingOrderStatus::Completed], true)) {
                throw new ManufacturingException("{$order->number} is {$order->status->label()}; returns and wastage are recorded on a batch that has drawn material.");
            }

            if (! in_array($kind, [ManufacturingOrderAdjustment::RETURN, ManufacturingOrderAdjustment::WASTAGE], true)) {
                throw new ManufacturingException('An adjustment is a return or wastage.');
            }

            if (! $quantity->isPositive()) {
                throw new ManufacturingException('The quantity must be greater than zero.');
            }

            /** @var ManufacturingOrderLine|null $line */
            $line = $order->lines->firstWhere('item_id', $item->id);

            if ($line === null) {
                throw new ManufacturingException("{$item->name} is not on {$order->number}.");
            }

            $consumed = BigDecimal::of($line->consumed_quantity ?? '0');
            $already = $order->adjustments()->where('item_id', $item->id)->get()
                ->reduce(fn (BigDecimal $c, ManufacturingOrderAdjustment $a) => $c->plus(BigDecimal::of($a->quantity)), BigDecimal::zero());

            if ($already->plus($quantity)->isGreaterThan($consumed)) {
                throw new ManufacturingException("Only {$consumed->minus($already)} of {$item->name} was issued to {$order->number} and not yet accounted for.");
            }

            if ($lot !== null && $lot->item_id !== $item->id) {
                throw new ManufacturingException("Batch {$lot->batch_number} is not {$item->name}.");
            }

            $transaction = null;

            if ($kind === ManufacturingOrderAdjustment::RETURN) {
                $store = $line->store_kind === StoreKind::Packaging
                    ? $this->warehouses->packagingStore($order->facility)
                    : $this->warehouses->rawMaterialStore($order->facility);

                $transaction = $this->ledger->receive(
                    $item, $store, $quantity, $lot,
                    InventoryTransactionType::ProductionReturn,
                    $order,
                    $reason ?? "Returned from {$order->number}",
                    $lot?->unit_cost,
                    $userId,
                );
            }

            return $order->adjustments()->create([
                'item_id' => $item->id,
                'lot_id' => $lot?->id,
                'kind' => $kind,
                'quantity' => (string) $quantity,
                'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
                'inventory_transaction_id' => $transaction?->id,
                'recorded_by' => $userId,
                'recorded_at' => now(),
            ]);
        });
    }
}
