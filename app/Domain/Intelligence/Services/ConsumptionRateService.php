<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Services;

use App\Domain\Inventory\Enums\InventoryTransactionType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How fast each material is used, from the ledger.
 *
 * The rate is the average daily quantity issued to production (plus
 * samples and dispatches) over the configured window. A material with
 * less history than the window is averaged over the days it has, never
 * fewer than the floor, so a new material is not read as being used at a
 * furious pace because of one batch.
 */
class ConsumptionRateService
{
    /** @var list<string> */
    public const CONSUMING_TYPES = [
        InventoryTransactionType::ProductionConsumption->value,
        InventoryTransactionType::Sample->value,
        InventoryTransactionType::SalesDispatch->value,
        InventoryTransactionType::MarketplaceSale->value,
    ];

    /**
     * Daily rates for a set of items, one query.
     *
     * @param  iterable<int>  $itemIds
     * @param  list<int>|null  $warehouseIds  narrow to stores of one facility; null means everywhere
     * @return Collection<int, array{daily_rate: BigDecimal, days: int, total: BigDecimal, first_at: CarbonImmutable|null, last_at: CarbonImmutable|null}>
     */
    public function ratesFor(iterable $itemIds, ?array $warehouseIds = null, ?CarbonImmutable $asOf = null): Collection
    {
        $asOf ??= CarbonImmutable::now();
        $window = $this->windowDays();
        $floor = (int) config('erp.intelligence.consumption_floor_days', 14);
        $from = $asOf->subDays($window);

        $ids = collect($itemIds)->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $rows = DB::table('inventory_transaction_lines as l')
            ->join('inventory_transactions as t', 't.id', '=', 'l.inventory_transaction_id')
            ->whereIn('l.item_id', $ids->all())
            ->whereIn('t.type', self::CONSUMING_TYPES)
            ->where('l.quantity', '<', 0)
            ->where('t.transacted_at', '>=', $from)
            ->where('t.transacted_at', '<=', $asOf)
            ->when($warehouseIds !== null, fn ($q) => $q->whereIn('l.warehouse_id', $warehouseIds))
            ->groupBy('l.item_id')
            ->selectRaw('l.item_id, SUM(ABS(l.quantity)) AS total, MIN(t.transacted_at) AS first_at, MAX(t.transacted_at) AS last_at')
            ->get();

        return $rows->mapWithKeys(function ($row) use ($asOf, $window, $floor): array {
            $total = BigDecimal::of($row->total);
            $first = CarbonImmutable::parse($row->first_at);
            $last = CarbonImmutable::parse($row->last_at);
            $days = max($floor, min($window, (int) ceil($first->diffInDays($asOf, true)) ?: 1));

            return [(int) $row->item_id => [
                'daily_rate' => $total->dividedBy($days, 6, RoundingMode::HalfUp),
                'days' => $days,
                'total' => $total,
                'first_at' => $first,
                'last_at' => $last,
            ]];
        });
    }

    public function windowDays(): int
    {
        return max(7, (int) config('erp.intelligence.consumption_window_days', 90));
    }
}
