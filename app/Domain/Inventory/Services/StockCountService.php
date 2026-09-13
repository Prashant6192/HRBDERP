<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\StockCountStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Models\StockCountLine;
use App\Domain\MasterData\Models\Item;
use App\Domain\Warehousing\Models\Warehouse;
use App\Support\Math\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
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
    ) {}

    public function start(Warehouse $warehouse, int $userId, ?string $notes = null): StockCount
    {
        return DB::transaction(function () use ($warehouse, $userId, $notes): StockCount {
            if (StockCount::query()->where('warehouse_id', $warehouse->id)->whereIn('status', [StockCountStatus::Counting->value, StockCountStatus::Submitted->value])->exists()) {
                throw new InvalidArgumentException("{$warehouse->code} already has a count in progress; finish or cancel it first.");
            }

            $count = StockCount::query()->create([
                'number' => $this->sequences->nextNumber('SC', now()->format('ym'), 4),
                'warehouse_id' => $warehouse->id,
                'status' => StockCountStatus::Counting,
                'notes' => $notes,
                'started_by' => $userId,
                'started_at' => now(),
            ]);

            $balances = StockBalance::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('on_hand', '>', 0)
                ->orderBy('item_id')
                ->orderBy('lot_id')
                ->get();

            foreach ($balances as $balance) {
                $count->lines()->create([
                    'item_id' => $balance->item_id,
                    'lot_id' => $balance->lot_id,
                    'system_quantity' => $balance->on_hand,
                ]);
            }

            return $count->fresh(['lines']);
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

            foreach ($count->lines as $line) {
                $variance = $line->variance();

                if ($variance === null || $variance->isZero()) {
                    continue;
                }

                $reason = "Stock count {$count->number}: system ".Decimal::strip($line->system_quantity).', found '.Decimal::strip($line->counted_quantity).($line->note ? " — {$line->note}" : '');

                $transaction = $variance->isPositive()
                    ? $this->ledger->receive($line->item, $count->warehouse, $variance, $line->lot, InventoryTransactionType::StockAdjustmentIn, $count, $reason, $line->lot?->unit_cost, $userId)
                    : $this->ledger->issue($line->item, $count->warehouse, $variance->abs(), $line->lot, InventoryTransactionType::StockAdjustmentOut, $count, $reason, $userId);

                $line->fill(['inventory_transaction_id' => $transaction->id])->save();
            }

            $count->fill(['status' => StockCountStatus::Approved, 'approved_by' => $userId, 'approved_at' => now()])->save();

            return $count->refresh();
        });
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
