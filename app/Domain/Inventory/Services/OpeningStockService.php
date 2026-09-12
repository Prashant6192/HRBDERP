<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\DTOs\LedgerLine;
use App\Domain\Inventory\DTOs\LedgerPosting;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Exceptions\OpeningStockException;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\InventoryTransaction;
use App\Domain\MasterData\Models\Item;
use App\Domain\Measurement\Exceptions\IncompatibleUnitsException;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Measurement\Services\UnitConversionService;
use App\Domain\Warehousing\Models\Warehouse;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Booking the stock a facility already holds on the day it goes live.
 *
 * There is no editable stock number. Each line becomes a lot (with its
 * batch, dates and rate) and one immutable OPENING_BALANCE posting in the
 * ledger, so opening stock is traceable like everything after it. The
 * facility's opening-stock switch is checked here, not just on the screen.
 */
class OpeningStockService
{
    public function __construct(
        private readonly InventoryLedgerService $ledger,
        private readonly UnitConversionService $conversions,
        private readonly BatchNumberGenerator $batchNumbers,
    ) {}

    /**
     * @param  list<array{item_id: int, quantity: string, uom_id?: int|null, batch_number?: string|null, manufactured_at?: string|null, expiry_at?: string|null, unit_cost?: string|null, remarks?: string|null}>  $lines
     */
    public function book(Warehouse $store, array $lines, int $userId, ?string $asOf = null, ?string $remarks = null): InventoryTransaction
    {
        if ($lines === []) {
            throw new OpeningStockException('Add at least one line.');
        }

        return DB::transaction(function () use ($store, $lines, $userId, $asOf, $remarks): InventoryTransaction {
            $store = Warehouse::query()->lockForUpdate()->with('facility')->findOrFail($store->id);

            if ($store->is_system || ! $store->is_active) {
                throw new OpeningStockException("{$store->name} is not an active store.");
            }

            if ($store->facility === null) {
                throw new OpeningStockException("{$store->name} does not belong to a facility.");
            }

            if (! $store->facility->is_active) {
                throw new OpeningStockException("{$store->facility->name} is deactivated.");
            }

            if (! $store->facility->opening_stock_enabled) {
                throw new OpeningStockException("Opening stock is switched off for {$store->facility->name}. An administrator can re-enable it in the facility settings.");
            }

            $date = $asOf ? CarbonImmutable::parse($asOf) : CarbonImmutable::today();
            $ledgerLines = [];

            foreach ($lines as $index => $line) {
                $item = Item::query()->with('stockUom')->findOrFail($line['item_id']);
                $quantity = $this->toStockUnit($item, BigDecimal::of($line['quantity']), $line['uom_id'] ?? null);

                if (! $quantity->isPositive()) {
                    throw new OpeningStockException('Line '.($index + 1).": the quantity for {$item->name} must be greater than zero.");
                }

                $manufacturedAt = ! empty($line['manufactured_at']) ? CarbonImmutable::parse($line['manufactured_at']) : null;
                $expiryAt = ! empty($line['expiry_at']) ? CarbonImmutable::parse($line['expiry_at']) : null;

                if ($expiryAt === null && $manufacturedAt !== null && $item->shelf_life_days) {
                    $expiryAt = $manufacturedAt->addDays((int) $item->shelf_life_days);
                }

                $unitCost = isset($line['unit_cost']) && $line['unit_cost'] !== null && $line['unit_cost'] !== '' ? BigDecimal::of($line['unit_cost']) : null;

                $lot = InventoryLot::create([
                    'item_id' => $item->id,
                    'batch_number' => $this->batchNumber($item, $line['batch_number'] ?? null, $manufacturedAt ?? $date),
                    'manufactured_at' => $manufacturedAt?->toDateString(),
                    'received_at' => $date->toDateString(),
                    'expiry_at' => $expiryAt?->toDateString(),
                    'qc_status' => LotQcStatus::NotRequired,
                    'initial_quantity' => $quantity->__toString(),
                    'unit_cost' => $unitCost?->__toString(),
                    'notes' => trim('Opening stock at '.$store->facility->name.'. '.($line['remarks'] ?? '')),
                    'created_by' => $userId,
                ]);

                $ledgerLines[] = new LedgerLine($item->id, $store->id, $quantity, $lot->id, null, $unitCost);
            }

            return $this->ledger->post(new LedgerPosting(
                type: InventoryTransactionType::OpeningBalance,
                warehouseId: $store->id,
                lines: $ledgerLines,
                reason: trim("Opening stock — {$store->facility->name} / {$store->name}. ".($remarks ?? '')),
                transactedAt: $date->endOfDay(),
                createdBy: $userId,
            ));
        });
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
            throw new OpeningStockException("Cannot convert {$quantity} {$from->code} of {$item->name} to {$item->stockUom->code}. Enter it in the stock unit or set a conversion on the material.");
        }
    }

    private function batchNumber(Item $item, ?string $requested, CarbonImmutable $date): string
    {
        $requested = trim((string) $requested);

        if ($requested !== '') {
            $exists = InventoryLot::query()->where('item_id', $item->id)->where('batch_number', $requested)->exists();

            if ($exists) {
                throw new OpeningStockException("{$item->name} already has a batch {$requested}. Add the quantity as a separate batch number.");
            }

            return $requested;
        }

        return $this->batchNumbers->generate($item, $date);
    }
}
