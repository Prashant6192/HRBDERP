<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\MasterData\Models\Item;
use App\Domain\Warehousing\Models\Warehouse;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Answers "how much do we have" in the several senses that question has.
 *
 *   on hand    — physically on the shelf
 *   reserved   — held for a production order
 *   available  — on hand minus reserved
 *   for production — available, in a QC-released lot that has not expired,
 *                    in an active store that is not a quarantine
 *
 * The last is the one that decides whether a batch can be made.
 */
class StockBalanceService
{
    public function onHand(Item $item, ?Warehouse $warehouse = null): BigDecimal
    {
        return $this->sum($this->balances($item, $warehouse), 'on_hand');
    }

    public function reserved(Item $item, ?Warehouse $warehouse = null): BigDecimal
    {
        return $this->sum($this->balances($item, $warehouse), 'reserved');
    }

    public function available(Item $item, ?Warehouse $warehouse = null): BigDecimal
    {
        return $this->onHand($item, $warehouse)->minus($this->reserved($item, $warehouse));
    }

    /**
     * Stock that production may actually draw on.
     *
     * @param  iterable<int>|null  $warehouseIds  Restrict to these stores; null means every active, non-quarantine store.
     */
    public function availableForProduction(Item $item, ?iterable $warehouseIds = null): BigDecimal
    {
        $total = BigDecimal::zero();

        foreach ($this->releasableBalances($item, $warehouseIds) as $balance) {
            $total = $total->plus($balance->available());
        }

        return $total;
    }

    /**
     * The balance rows production may draw on, in the order they should be
     * drawn: earliest expiry first, then the oldest lot.
     *
     * @param  iterable<int>|null  $warehouseIds
     * @return Collection<int, StockBalance>
     */
    public function releasableBalances(Item $item, ?iterable $warehouseIds = null, bool $lock = false): Collection
    {
        $query = StockBalance::query()
            ->where('item_id', $item->id)
            ->where('on_hand', '>', 0)
            ->whereIn('warehouse_id', $this->issuableWarehouseIds($warehouseIds));

        if ($lock) {
            $query->lockForUpdate();
        }

        $balances = $query->get();

        $lots = InventoryLot::query()
            ->whereIn('id', $balances->pluck('lot_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        return $balances
            ->filter(function (StockBalance $balance) use ($lots): bool {
                if ($balance->lot_id === null) {
                    // Stock with no lot has no QC dimension and no expiry.
                    return true;
                }

                $lot = $lots->get($balance->lot_id);

                return $lot !== null && $lot->isReleasable();
            })
            ->each(fn (StockBalance $balance) => $balance->setRelation('lot', $lots->get($balance->lot_id)))
            ->sortBy([
                fn (StockBalance $a, StockBalance $b): int => $this->compareExpiry($a, $b),
                fn (StockBalance $a, StockBalance $b): int => $a->lot_id <=> $b->lot_id,
            ])
            ->values();
    }

    /**
     * On hand, reserved and available for every item in a warehouse, with
     * the item loaded — what a store dashboard needs.
     *
     * @return Collection<int, array{item: Item, on_hand: BigDecimal, reserved: BigDecimal, available: BigDecimal}>
     */
    public function summaryForWarehouse(Warehouse $warehouse): Collection
    {
        $rows = StockBalance::query()
            ->selectRaw('item_id, SUM(on_hand) AS on_hand, SUM(reserved) AS reserved')
            ->where('warehouse_id', $warehouse->id)
            ->groupBy('item_id')
            ->get();

        $items = Item::query()
            ->with('stockUom:id,code,display_scale')
            ->whereIn('id', $rows->pluck('item_id'))
            ->get()
            ->keyBy('id');

        return $rows
            ->map(function ($row) use ($items): ?array {
                $item = $items->get($row->item_id);

                if ($item === null) {
                    return null;
                }

                $onHand = BigDecimal::of($row->on_hand);
                $reserved = BigDecimal::of($row->reserved);

                return [
                    'item' => $item,
                    'on_hand' => $onHand,
                    'reserved' => $reserved,
                    'available' => $onHand->minus($reserved),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Recompute every cached balance from the ledger and the open
     * reservations. Returns the number of positions rebuilt.
     *
     * The ledger is the truth; this is how the cache is brought back to it
     * after a repair, a restore, or a bug.
     */
    public function rebuild(): int
    {
        return DB::transaction(function (): int {
            $onHand = DB::table('inventory_transaction_lines')
                ->selectRaw('item_id, warehouse_id, lot_id, SUM(quantity) AS on_hand')
                ->groupBy('item_id', 'warehouse_id', 'lot_id')
                ->get();

            $reserved = DB::table('stock_reservations')
                ->selectRaw('item_id, warehouse_id, lot_id, SUM(quantity - consumed_quantity) AS reserved')
                ->where('status', ReservationStatus::Active->value)
                ->groupBy('item_id', 'warehouse_id', 'lot_id')
                ->get()
                ->keyBy(fn ($r): string => "{$r->item_id}:{$r->warehouse_id}:".($r->lot_id ?? 'none'));

            DB::table('stock_balances')->delete();

            $count = 0;

            foreach ($onHand as $row) {
                $key = "{$row->item_id}:{$row->warehouse_id}:".($row->lot_id ?? 'none');

                DB::table('stock_balances')->insert([
                    'item_id' => $row->item_id,
                    'warehouse_id' => $row->warehouse_id,
                    'lot_id' => $row->lot_id,
                    'on_hand' => $row->on_hand,
                    'reserved' => $reserved->get($key)?->reserved ?? 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $count++;
            }

            return $count;
        });
    }

    // ---- Internals -----------------------------------------------------------

    /**
     * @return Builder<StockBalance>
     */
    private function balances(Item $item, ?Warehouse $warehouse): Builder
    {
        $query = StockBalance::query()->where('item_id', $item->id);

        if ($warehouse !== null) {
            $query->where('warehouse_id', $warehouse->id);
        }

        return $query;
    }

    /**
     * @param  Builder<StockBalance>  $query
     */
    private function sum(Builder $query, string $column): BigDecimal
    {
        $value = $query->sum($column);

        return BigDecimal::of($value === null ? '0' : (string) $value);
    }

    /**
     * @param  iterable<int>|null  $warehouseIds
     * @return list<int>
     */
    private function issuableWarehouseIds(?iterable $warehouseIds): array
    {
        $query = Warehouse::query()->availableForIssue();

        if ($warehouseIds !== null) {
            $query->whereIn('id', collect($warehouseIds)->all());
        }

        return $query->pluck('id')->all();
    }

    private function compareExpiry(StockBalance $a, StockBalance $b): int
    {
        $ea = $a->getRelation('lot')?->expiry_at;
        $eb = $b->getRelation('lot')?->expiry_at;

        // No expiry sorts last: use what will go off first.
        if ($ea === null && $eb === null) {
            return 0;
        }

        if ($ea === null) {
            return 1;
        }

        if ($eb === null) {
            return -1;
        }

        return $ea <=> $eb;
    }
}
