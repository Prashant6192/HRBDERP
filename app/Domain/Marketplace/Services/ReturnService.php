<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Services;

use App\Domain\Inventory\DTOs\LedgerLine;
use App\Domain\Inventory\DTOs\LedgerPosting;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Models\InventoryTransaction;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Inventory\Services\SequenceService;
use App\Domain\Marketplace\Enums\ClaimStatus;
use App\Domain\Marketplace\Enums\ReturnKind;
use App\Domain\Marketplace\Enums\ShipmentStatus;
use App\Domain\Marketplace\Enums\StockState;
use App\Domain\Marketplace\Exceptions\OnlineOrderException;
use App\Domain\Marketplace\Models\Shipment;
use App\Domain\Marketplace\Models\ShipmentReturn;
use App\Domain\MasterData\Models\Item;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\StoreCategory;
use App\Domain\Warehousing\Models\Warehouse;
use App\Domain\Warehousing\Services\StoreService;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * A parcel that went out and came back.
 *
 * Returns are received against the parcel they left in, so what comes
 * back is compared with what left. Each product is counted as good (back
 * on the finished goods shelf, sellable), damaged (into the facility's
 * damaged goods store: dead stock, kept until someone decides to write it
 * off) or not received. The goods go back into the same batches they left
 * from, so a returned bottle keeps its batch number. Damaged or missing
 * goods, or a wrong item sent back, open a claim on the marketplace with
 * its deadline.
 */
class ReturnService
{
    public function __construct(
        private readonly InventoryLedgerService $ledger,
        private readonly SequenceService $sequences,
        private readonly StoreService $stores,
    ) {}

    /**
     * What left in the parcel, product by product, and from which batches.
     *
     * @return array<int, array{item: Item, units: BigDecimal, lots: list<array{lot_id: int|null, units: BigDecimal}>}>
     */
    public function sent(Shipment $shipment): array
    {
        $posting = $this->salePosting($shipment);

        if ($posting === null) {
            return [];
        }

        $posting->loadMissing('lines.item');
        $sent = [];

        foreach ($posting->lines as $line) {
            $units = BigDecimal::of($line->quantity)->abs();

            if (! $units->isPositive()) {
                continue;
            }

            $sent[$line->item_id] ??= ['item' => $line->item, 'units' => BigDecimal::zero(), 'lots' => []];
            $sent[$line->item_id]['units'] = $sent[$line->item_id]['units']->plus($units);
            $sent[$line->item_id]['lots'][] = ['lot_id' => $line->lot_id, 'units' => $units];
        }

        return $sent;
    }

    /**
     * Why this parcel cannot be received back, or null if it can.
     */
    public function cannotReturn(Shipment $shipment): ?string
    {
        $shipment->loadMissing('shipmentReturn');

        if ($shipment->shipmentReturn !== null) {
            return "{$shipment->reference()} was already received back as {$shipment->shipmentReturn->number}.";
        }

        return match ($shipment->status) {
            ShipmentStatus::Uploaded, ShipmentStatus::Printed => "{$shipment->reference()} was never packed, so nothing left the depot. Cancel it instead.",
            ShipmentStatus::Cancelled => "{$shipment->reference()} was cancelled before it left.",
            ShipmentStatus::Returned => "{$shipment->reference()} has already come back.",
            default => null,
        };
    }

    /**
     * Receive a parcel back.
     *
     * @param  array<int, array{good?: string|int|float|null, damaged?: string|int|float|null}>  $counts  keyed by item id
     */
    public function receive(
        Shipment $shipment,
        ReturnKind $kind,
        array $counts,
        User $user,
        ?Warehouse $into = null,
        ?string $notes = null,
        bool $wrongItem = false,
        ?string $returnAwb = null,
    ): ShipmentReturn {
        return DB::transaction(function () use ($shipment, $kind, $counts, $user, $into, $notes, $wrongItem, $returnAwb): ShipmentReturn {
            $shipment = Shipment::query()->lockForUpdate()->with(['marketplace', 'warehouse.facility'])->findOrFail($shipment->id);

            if (($why = $this->cannotReturn($shipment)) !== null) {
                throw new OnlineOrderException($why);
            }

            $sent = $this->sent($shipment);

            if ($sent === []) {
                throw new OnlineOrderException("No stock left the store for {$shipment->reference()}, so there is nothing to take back in.");
            }

            $into ??= $shipment->warehouse;
            $this->assertGoodStore($into);
            $facility = $into->facility;

            $rows = [];
            $anyShort = false;

            foreach ($sent as $itemId => $left) {
                $good = $this->quantity($counts[$itemId]['good'] ?? 0, $left['item']);
                $damaged = $this->quantity($counts[$itemId]['damaged'] ?? 0, $left['item']);

                if ($good->plus($damaged)->isGreaterThan($left['units'])) {
                    throw new OnlineOrderException(sprintf(
                        '%s: %s left in the parcel, but %s good and %s damaged were counted back.',
                        $left['item']->name,
                        $this->show($left['units']),
                        $this->show($good),
                        $this->show($damaged),
                    ));
                }

                $missing = $left['units']->minus($good)->minus($damaged);
                $anyShort = $anyShort || $damaged->isPositive() || $missing->isPositive();
                $rows[$itemId] = compact('good', 'damaged', 'missing') + ['sent' => $left['units'], 'lots' => $left['lots']];
            }

            $needsDamagedStore = collect($rows)->contains(fn (array $r) => $r['damaged']->isPositive());
            $damagedStore = $needsDamagedStore ? $this->damagedStore($facility, $user) : null;

            $now = now();
            $claim = $anyShort || $wrongItem;
            $windowHours = $shipment->marketplace->claim_window_hours;

            $return = ShipmentReturn::create([
                'number' => $this->sequences->nextNumber('RT', $now->format('ym')),
                'shipment_id' => $shipment->id,
                'marketplace_id' => $shipment->marketplace_id,
                'brand_id' => $shipment->brand_id,
                'facility_id' => $facility->id,
                'kind' => $kind,
                'return_awb' => $returnAwb !== null && trim($returnAwb) !== '' ? strtoupper(trim($returnAwb)) : null,
                'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
                'wrong_item' => $wrongItem,
                'claim_status' => $claim ? ClaimStatus::Open : ClaimStatus::None,
                'claim_deadline_at' => $claim && $windowHours ? $now->copy()->addHours($windowHours) : null,
                'received_by' => $user->id,
                'received_at' => $now,
            ]);

            $ledgerLines = [];

            foreach ($rows as $itemId => $row) {
                $return->lines()->create([
                    'item_id' => $itemId,
                    'sent' => (string) $row['sent'],
                    'good' => (string) $row['good'],
                    'damaged' => (string) $row['damaged'],
                    'missing' => (string) $row['missing'],
                    'good_warehouse_id' => $row['good']->isPositive() ? $into->id : null,
                    'damaged_warehouse_id' => $row['damaged']->isPositive() ? $damagedStore?->id : null,
                ]);

                // Back into the batches the goods left from: good ones first,
                // then damaged ones, batch by batch.
                $lots = $row['lots'];
                array_push($ledgerLines, ...$this->spread($itemId, $into->id, $row['good'], $lots));

                if ($damagedStore !== null) {
                    array_push($ledgerLines, ...$this->spread($itemId, $damagedStore->id, $row['damaged'], $lots));
                }
            }

            if ($ledgerLines !== []) {
                $posting = $this->ledger->post(new LedgerPosting(
                    type: InventoryTransactionType::MarketplaceReturn,
                    warehouseId: $into->id,
                    lines: $ledgerLines,
                    reference: $return,
                    reason: sprintf(
                        '%s: %s order %s, AWB %s came back (%s)',
                        $return->number,
                        $shipment->marketplace->name,
                        $shipment->order_number ?? '—',
                        $shipment->awb ?? '—',
                        $kind->label(),
                    ),
                    createdBy: $user->id,
                ));

                $return->forceFill(['inventory_transaction_id' => $posting->id])->save();
            }

            $shipment->fill([
                'status' => ShipmentStatus::Returned,
                'stock_state' => StockState::Returned,
                'returned_at' => $now,
            ])->save();

            return $return->refresh()->load(['lines.item', 'shipment']);
        });
    }

    /**
     * The office records what happened with the marketplace.
     */
    public function updateClaim(ShipmentReturn $return, ClaimStatus $status, ?string $reference = null, ?string $amount = null, ?string $note = null): ShipmentReturn
    {
        if ($status === ClaimStatus::None && $return->claim_status !== ClaimStatus::None) {
            // A claim can be closed as not needed.
            $note ??= 'Closed without a claim.';
        }

        $return->fill([
            'claim_status' => $status,
            'claim_reference' => $reference !== null && trim($reference) !== '' ? trim($reference) : $return->claim_reference,
            'claim_amount' => $amount !== null && trim($amount) !== '' ? $amount : $return->claim_amount,
            'claim_note' => $note !== null && trim($note) !== '' ? trim($note) : $return->claim_note,
        ])->save();

        return $return->refresh();
    }

    /**
     * The facility's damaged goods store, opened the first time something
     * damaged comes back there.
     */
    public function damagedStore(Facility $facility, User $user): Warehouse
    {
        $existing = Warehouse::query()
            ->where('facility_id', $facility->id)
            ->where('type', WarehouseType::Damaged->value)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $category = StoreCategory::query()->where('kind', WarehouseType::Damaged->value)->where('is_active', true)->orderBy('id')->first();

        if ($category === null) {
            throw new OnlineOrderException('There is no Damaged Goods store category to open a damaged store with. Add one under store categories first.');
        }

        return $this->stores->create($facility, [
            'store_category_id' => $category->id,
            'name' => 'Damaged Goods Store',
            'notes' => 'Opened automatically for damaged online returns. Stock here is not sellable.',
        ], $user->id);
    }

    private function salePosting(Shipment $shipment): ?InventoryTransaction
    {
        return $shipment->transactions()
            ->where('type', InventoryTransactionType::MarketplaceSale->value)
            ->whereDoesntHave('reversal')
            ->latest('id')
            ->first();
    }

    private function assertGoodStore(Warehouse $store): void
    {
        $store->loadMissing('facility');

        if (! $store->is_active || $store->is_system || $store->is_quarantine || $store->type !== WarehouseType::FinishedGoods || $store->facility === null) {
            throw new OnlineOrderException("{$store->name} is not a finished goods store good returns can go back into.");
        }
    }

    /**
     * @param  list<array{lot_id: int|null, units: BigDecimal}>  $lots  what is left to fill, updated as it goes
     * @return list<LedgerLine>
     */
    private function spread(int $itemId, int $warehouseId, BigDecimal $quantity, array &$lots): array
    {
        $lines = [];

        foreach ($lots as $i => $lot) {
            if (! $quantity->isPositive()) {
                break;
            }

            $take = $quantity->isLessThan($lot['units']) ? $quantity : $lot['units'];

            if (! $take->isPositive()) {
                continue;
            }

            $lines[] = new LedgerLine($itemId, $warehouseId, $take, $lot['lot_id']);
            $lots[$i]['units'] = $lot['units']->minus($take);
            $quantity = $quantity->minus($take);
        }

        return $lines;
    }

    private function quantity(string|int|float|null $value, Item $item): BigDecimal
    {
        $value = trim((string) ($value ?? '0'));

        try {
            $quantity = BigDecimal::of($value === '' ? '0' : $value);
        } catch (\Throwable) {
            throw new OnlineOrderException("Count {$item->name} as a number.");
        }

        if ($quantity->isNegative()) {
            throw new OnlineOrderException("A count for {$item->name} cannot be below zero.");
        }

        return $quantity;
    }

    private function show(BigDecimal $value): string
    {
        return (string) $value->strippedOfTrailingZeros();
    }
}
