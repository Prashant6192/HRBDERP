<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\DTOs\LedgerLine;
use App\Domain\Inventory\DTOs\LedgerPosting;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\StockCountStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\InventoryTransaction;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Models\StockCountLine;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Marketplace\Enums\StockState;
use App\Domain\Marketplace\Models\Shipment;
use App\Domain\MasterData\Models\Item;
use App\Domain\Warehousing\Models\Warehouse;
use App\Support\Math\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Stock counts.
 *
 * A count freezes what the system says a store holds, item by item and
 * batch by batch, and records what was found on the shelf. Once someone
 * other than the counter approves it, every difference is posted as an
 * adjustment through the ledger, referencing the count, so the system
 * matches the shelf and the correction is on record. The inventory
 * accuracy figure is read from the lines.
 */
class StockCountService
{
    public function __construct(
        private readonly InventoryLedgerService $ledger,
        private readonly SequenceService $sequences,
        private readonly InventoryReservationService $reservations,
    ) {}

    /**
     * Start a count. When the shelf was counted earlier and is only being
     * entered now ($asAt), each line's system quantity is what the store
     * held at that moment: today's stock less everything that moved since.
     * The difference posted on approval is then the true one, whatever was
     * packed or scanned in after the count.
     */
    public function start(Warehouse $warehouse, int $userId, ?string $notes = null, ?CarbonImmutable $asAt = null): StockCount
    {
        return DB::transaction(function () use ($warehouse, $userId, $notes, $asAt): StockCount {
            if (StockCount::query()->where('warehouse_id', $warehouse->id)->whereIn('status', [StockCountStatus::Counting->value, StockCountStatus::Submitted->value])->exists()) {
                throw new InvalidArgumentException("{$warehouse->code} already has a count in progress; finish or cancel it first.");
            }

            if ($asAt !== null && $asAt->isFuture()) {
                throw new InvalidArgumentException('The shelf cannot have been counted in the future.');
            }

            $count = StockCount::query()->create([
                'number' => $this->sequences->nextNumber('SC', now()->format('ym'), 4),
                'warehouse_id' => $warehouse->id,
                'status' => StockCountStatus::Counting,
                'notes' => $notes,
                'started_by' => $userId,
                'started_at' => now(),
                'counted_as_at' => $asAt,
            ]);

            /** @var array<string, array{item_id: int, lot_id: int|null, quantity: BigDecimal}> $positions */
            $positions = [];

            foreach (StockBalance::query()->where('warehouse_id', $warehouse->id)->where('on_hand', '<>', 0)->get() as $balance) {
                $positions["{$balance->item_id}|{$balance->lot_id}"] = [
                    'item_id' => (int) $balance->item_id,
                    'lot_id' => $balance->lot_id === null ? null : (int) $balance->lot_id,
                    'quantity' => BigDecimal::of($balance->on_hand),
                ];
            }

            if ($asAt !== null) {
                $moved = DB::table('inventory_transaction_lines as l')
                    ->join('inventory_transactions as t', 't.id', '=', 'l.inventory_transaction_id')
                    ->where('l.warehouse_id', $warehouse->id)
                    ->where('t.transacted_at', '>', $asAt)
                    ->groupBy('l.item_id', 'l.lot_id')
                    ->selectRaw('l.item_id, l.lot_id, SUM(l.quantity) AS quantity')
                    ->get();

                foreach ($moved as $m) {
                    $key = "{$m->item_id}|{$m->lot_id}";
                    $positions[$key] ??= ['item_id' => (int) $m->item_id, 'lot_id' => $m->lot_id === null ? null : (int) $m->lot_id, 'quantity' => BigDecimal::zero()];
                    $positions[$key]['quantity'] = $positions[$key]['quantity']->minus(BigDecimal::of((string) $m->quantity));
                }
            }

            uasort($positions, fn (array $a, array $b) => [$a['item_id'], $a['lot_id'] ?? PHP_INT_MAX] <=> [$b['item_id'], $b['lot_id'] ?? PHP_INT_MAX]);

            foreach ($positions as $position) {
                if ($position['quantity']->isZero()) {
                    continue;
                }

                $count->lines()->create([
                    'item_id' => $position['item_id'],
                    'lot_id' => $position['lot_id'],
                    'system_quantity' => (string) $position['quantity'],
                ]);
            }

            return $count->fresh(['lines']);
        });
    }

    /**
     * Record one total for a product, as a register has it: no batches.
     * The total is laid over the product's batches oldest first, so a
     * shortage comes off the newest stock — the oldest is what waiting
     * parcels hold, and must stay to be packed; a surplus goes onto the
     * newest batch. A product the system does not hold here at all is
     * added as stock without a batch.
     *
     * @return list<StockCountLine>
     */
    public function recordProduct(StockCount $count, Item $item, BigDecimal|string $counted, int $userId, ?string $note = null): array
    {
        return DB::transaction(function () use ($count, $item, $counted, $userId, $note): array {
            $count = StockCount::query()->lockForUpdate()->findOrFail($count->getKey());

            if ($count->status !== StockCountStatus::Counting) {
                throw new InvalidArgumentException("{$count->number} is {$count->status->label()}; nothing more can be counted on it.");
            }

            $remaining = BigDecimal::of($counted);

            if ($remaining->isNegative()) {
                throw new InvalidArgumentException('A counted quantity cannot be negative.');
            }

            $lines = $count->lines()->where('item_id', $item->id)->get()
                // Oldest batch first; stock without a batch counts as newest.
                ->sortBy(fn (StockCountLine $l) => $l->lot_id ?? PHP_INT_MAX)
                ->values();

            if ($lines->isEmpty()) {
                $lines = collect([$count->lines()->create(['item_id' => $item->id, 'lot_id' => null, 'system_quantity' => '0'])]);
            }

            $found = [];

            foreach ($lines as $line) {
                $system = BigDecimal::of($line->system_quantity);
                $here = $system->isPositive() ? BigDecimal::min($system, $remaining) : BigDecimal::zero();
                $remaining = $remaining->minus($here);
                $found[$line->id] = $here;
            }

            // More on the shelf than the system knew: onto the newest batch.
            if ($remaining->isPositive()) {
                $newest = $lines->last();
                $found[$newest->id] = $found[$newest->id]->plus($remaining);
            }

            foreach ($lines as $line) {
                $line->fill([
                    'counted_quantity' => (string) $found[$line->id],
                    'note' => $note !== null && trim($note) !== '' ? trim($note) : $line->note,
                    'counted_by' => $userId,
                    'counted_at' => now(),
                ])->save();
            }

            return $lines->all();
        });
    }

    /**
     * Products the counter did not count stay as the system has them.
     *
     * @return int the lines kept
     */
    public function keepUncounted(StockCount $count, int $userId): int
    {
        return DB::transaction(function () use ($count, $userId): int {
            $count = StockCount::query()->lockForUpdate()->findOrFail($count->getKey());

            if ($count->status !== StockCountStatus::Counting) {
                throw new InvalidArgumentException("{$count->number} is {$count->status->label()}; nothing more can be counted on it.");
            }

            $lines = $count->lines()->whereNull('counted_quantity')->get();

            foreach ($lines as $line) {
                $line->fill([
                    'counted_quantity' => $line->system_quantity,
                    'note' => $line->note ?? 'Not counted: kept as the system has it.',
                    'counted_by' => $userId,
                    'counted_at' => now(),
                ])->save();
            }

            return $lines->count();
        });
    }

    /**
     * Record what was found for one line. A batch not on the sheet (found
     * on the shelf but not in the system) is added with a zero system
     * quantity.
     */
    public function record(StockCount $count, Item $item, ?InventoryLot $lot, BigDecimal|string $counted, int $userId, ?string $note = null): StockCountLine
    {
        return DB::transaction(function () use ($count, $item, $lot, $counted, $userId, $note): StockCountLine {
            $count = StockCount::query()->lockForUpdate()->findOrFail($count->getKey());

            if ($count->status !== StockCountStatus::Counting) {
                throw new InvalidArgumentException("{$count->number} is {$count->status->label()}; nothing more can be counted on it.");
            }

            $counted = BigDecimal::of($counted);

            if ($counted->isNegative()) {
                throw new InvalidArgumentException('A counted quantity cannot be negative.');
            }

            if ($lot !== null && $lot->item_id !== $item->id) {
                throw new InvalidArgumentException("Batch {$lot->batch_number} is not {$item->name}.");
            }

            $line = $count->lines()->where('item_id', $item->id)->where('lot_id', $lot?->id)->first();

            if ($line === null) {
                $line = $count->lines()->create(['item_id' => $item->id, 'lot_id' => $lot?->id, 'system_quantity' => '0']);
            }

            $line->fill([
                'counted_quantity' => (string) $counted,
                'note' => $note !== null && trim($note) !== '' ? trim($note) : $line->note,
                'counted_by' => $userId,
                'counted_at' => now(),
            ])->save();

            return $line;
        });
    }

    public function submit(StockCount $count, int $userId): StockCount
    {
        return DB::transaction(function () use ($count, $userId): StockCount {
            $count = StockCount::query()->lockForUpdate()->with('lines')->findOrFail($count->getKey());

            if ($count->status !== StockCountStatus::Counting) {
                throw new InvalidArgumentException("{$count->number} is {$count->status->label()}.");
            }

            $uncounted = $count->lines->whereNull('counted_quantity')->count();

            if ($uncounted > 0) {
                throw new InvalidArgumentException("{$uncounted} line".($uncounted === 1 ? ' has' : 's have').' not been counted yet. Count everything on the sheet, entering 0 where nothing was found.');
            }

            $count->fill(['status' => StockCountStatus::Submitted, 'submitted_by' => $userId, 'submitted_at' => now()])->save();

            return $count->refresh();
        });
    }

    /**
     * Post the differences. Maker and checker must differ.
     */
    public function approve(StockCount $count, int $userId): StockCount
    {
        return DB::transaction(function () use ($count, $userId): StockCount {
            $count = StockCount::query()->lockForUpdate()->with(['lines.item', 'lines.lot', 'warehouse'])->findOrFail($count->getKey());

            if ($count->status !== StockCountStatus::Submitted) {
                throw new InvalidArgumentException("{$count->number} is {$count->status->label()}; only a submitted count can be approved.");
            }

            $counters = $count->lines->pluck('counted_by')->push($count->started_by)->push($count->submitted_by)->filter()->unique();

            if ($counters->contains($userId)) {
                throw new InvalidArgumentException('Whoever counted or submitted cannot approve the count. Maker and checker must differ.');
            }

            // Each difference is posted against the store as it is now. The
            // count's batches are what the store held when it was counted;
            // since then parcels may have taken stock out of them, or hold
            // it, so a shortage is taken from its own batch first and from
            // the product's other batches for what that batch no longer has.
            foreach ($count->lines->groupBy('item_id') as $lines) {
                $item = $lines->first()->item;
                $variance = fn (StockCountLine $l) => BigDecimal::of($l->counted_quantity ?? $l->system_quantity)->minus(BigDecimal::of($l->system_quantity));

                foreach ($lines->filter(fn (StockCountLine $l) => $variance($l)->isPositive())->groupBy('lot_id') as $found) {
                    $quantity = $found->reduce(fn (BigDecimal $c, StockCountLine $l) => $c->plus($variance($l)), BigDecimal::zero());
                    $lot = $found->first()->lot;
                    $transaction = $this->ledger->receive($item, $count->warehouse, $quantity, $lot, InventoryTransactionType::StockAdjustmentIn, $count, $this->reason($count, $found), $lot?->unit_cost, $userId);
                    $found->each(fn (StockCountLine $l) => $l->fill(['inventory_transaction_id' => $transaction->id])->save());
                }

                $short = $lines->filter(fn (StockCountLine $l) => $variance($l)->isNegative());

                if ($short->isEmpty()) {
                    continue;
                }

                $quantity = $short->reduce(fn (BigDecimal $c, StockCountLine $l) => $c->plus($variance($l)->abs()), BigDecimal::zero());
                $transaction = $this->takeMissing($count, $item, $quantity, $short->pluck('lot_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(), $this->reason($count, $short), $userId);

                if ($transaction !== null) {
                    $short->each(fn (StockCountLine $l) => $l->fill(['inventory_transaction_id' => $transaction->id])->save());
                }
            }

            $count->fill(['status' => StockCountStatus::Approved, 'approved_by' => $userId, 'approved_at' => now()])->save();

            return $count->refresh();
        });
    }

    /**
     * "Stock count SC-…: system 100, found 90 — note" for the lines posted together.
     *
     * @param  Collection<int, StockCountLine>  $lines
     */
    private function reason(StockCount $count, $lines): string
    {
        $system = $lines->reduce(fn (BigDecimal $c, StockCountLine $l) => $c->plus(BigDecimal::of($l->system_quantity)), BigDecimal::zero());
        $found = $lines->reduce(fn (BigDecimal $c, StockCountLine $l) => $c->plus(BigDecimal::of($l->counted_quantity ?? $l->system_quantity)), BigDecimal::zero());
        $note = $lines->pluck('note')->filter()->unique()->implode('; ');

        return "Stock count {$count->number}: system ".Decimal::strip((string) $system).', found '.Decimal::strip((string) $found).($note !== '' ? " — {$note}" : '');
    }

    /**
     * Less on the shelf than the system had: taken from the store as it is
     * now, from the counted batches first and then the newest, from stock
     * nothing holds. If that is not
     * enough, stock held for parcels not yet sent is let go of first: the
     * shelf does not have it, so those parcels are short until stock comes
     * in. What the store no longer holds at all cannot be taken, and the
     * posting says so.
     */
    /**
     * @param  list<int>  $lotsFirst
     */
    private function takeMissing(StockCount $count, Item $item, BigDecimal $quantity, array $lotsFirst, string $reason, int $userId): ?InventoryTransaction
    {
        $warehouse = $count->warehouse;
        $balances = fn () => StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('item_id', $item->id)
            ->where('on_hand', '>', 0)
            ->lockForUpdate()
            ->get()
            ->sortBy([
                fn (StockBalance $a, StockBalance $b) => (int) ! in_array((int) $a->lot_id, $lotsFirst, true) <=> (int) ! in_array((int) $b->lot_id, $lotsFirst, true),
                fn (StockBalance $a, StockBalance $b) => ($b->lot_id ?? PHP_INT_MAX) <=> ($a->lot_id ?? PHP_INT_MAX),
            ])
            ->values();

        $free = $balances()->reduce(fn (BigDecimal $c, StockBalance $b) => $c->plus($b->onHand()->minus($b->reserved())), BigDecimal::zero());

        if ($free->isLessThan($quantity)) {
            $this->letGoOfParcels($item, $warehouse, $quantity->minus($free));
        }

        $need = $quantity;
        $lines = [];

        foreach ($balances() as $balance) {
            $take = BigDecimal::min($need, $balance->onHand()->minus($balance->reserved()));

            if (! $take->isPositive()) {
                continue;
            }

            $lines[] = new LedgerLine($item->id, $warehouse->id, $take->negated(), $balance->lot_id);
            $need = $need->minus($take);

            if ($need->isZero()) {
                break;
            }
        }

        if ($need->isPositive()) {
            $reason .= ' — '.Decimal::strip((string) $need).' could not be taken: the store no longer holds it';
        }

        if ($lines === []) {
            return null;
        }

        return $this->ledger->post(new LedgerPosting(
            type: InventoryTransactionType::StockAdjustmentOut,
            warehouseId: $warehouse->id,
            lines: $lines,
            reference: $count,
            reason: $reason,
            createdBy: $userId,
        ));
    }

    /**
     * Let go of stock held for parcels not yet sent, newest parcel first,
     * until $quantity is free; those parcels wait for stock again.
     */
    private function letGoOfParcels(Item $item, Warehouse $warehouse, BigDecimal $quantity): void
    {
        $held = StockReservation::query()
            ->active()
            ->where('item_id', $item->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('reservable_type', (new Shipment)->getMorphClass())
            ->orderByDesc('id')
            ->get();

        foreach ($held as $reservation) {
            if (! $quantity->isPositive()) {
                break;
            }

            $quantity = $quantity->minus($reservation->outstanding());
            $this->reservations->release($reservation);

            Shipment::query()->whereKey($reservation->reservable_id)
                ->where('stock_state', StockState::Reserved->value)
                ->update(['stock_state' => StockState::Short->value]);
        }
    }

    public function cancel(StockCount $count): StockCount
    {
        return DB::transaction(function () use ($count): StockCount {
            $count = StockCount::query()->lockForUpdate()->findOrFail($count->getKey());

            if ($count->status === StockCountStatus::Approved) {
                throw new InvalidArgumentException("{$count->number} is approved and its adjustments posted; it cannot be cancelled.");
            }

            $count->fill(['status' => StockCountStatus::Cancelled, 'cancelled_at' => now()])->save();

            return $count->refresh();
        });
    }

    /**
     * Inventory accuracy: the share of counted lines where the shelf
     * matched the system, and the value of the differences.
     *
     * @return array{lines: int, counted: int, accurate: int, accuracy_percent: string|null, variance_value: string}
     */
    public function accuracy(StockCount $count): array
    {
        // Read the lines afresh: the screen may have loaded them with other
        // columns, and the cost must come from the batch or the item.
        $lines = $count->lines()->with(['item:id,standard_cost', 'lot:id,unit_cost'])->get();
        $counted = $lines->whereNotNull('counted_quantity');
        $accurate = $counted->filter(fn (StockCountLine $l) => $l->variance()?->isZero() ?? false)->count();
        $value = $counted->reduce(function (BigDecimal $c, StockCountLine $l): BigDecimal {
            $cost = BigDecimal::of($l->lot?->unit_cost ?? $l->item?->standard_cost ?? '0');

            return $c->plus(($l->variance() ?? BigDecimal::zero())->abs()->multipliedBy($cost));
        }, BigDecimal::zero());

        return [
            'lines' => $lines->count(),
            'counted' => $counted->count(),
            'accurate' => $accurate,
            'accuracy_percent' => $counted->count() === 0 ? null : (string) BigDecimal::of($accurate)->multipliedBy(100)->dividedBy($counted->count(), 1, RoundingMode::HalfUp),
            'variance_value' => (string) $value->toScale(2, RoundingMode::HalfUp),
        ];
    }
}
