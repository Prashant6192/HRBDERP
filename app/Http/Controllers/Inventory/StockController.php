<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Enums\StockAlertLevel;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Services\StockAlertService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Warehouse;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What is in a store, and how worried to be about each line of it.
 */
class StockController extends Controller
{
    public function __construct(
        private readonly StockBalanceService $balances,
        private readonly StockAlertService $alerts,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('inventory.view');

        $warehouses = Warehouse::query()->active()->orderBy('is_quarantine')->orderBy('code')->get(['id', 'code', 'name', 'type', 'is_quarantine']);

        $selected = $request->integer('warehouse') > 0
            ? $warehouses->firstWhere('id', $request->integer('warehouse'))
            : null;

        // The navigation names stores by what they hold, not by id.
        if ($selected === null && ($storeType = WarehouseType::tryFrom((string) $request->query('store'))) !== null) {
            $selected = $warehouses->first(fn (Warehouse $w) => ! $w->is_quarantine && $w->type === $storeType);
        }

        // Land on the raw material store by default: it is where the day starts.
        $selected ??= $warehouses->first(fn (Warehouse $w) => ! $w->is_quarantine && $w->type === WarehouseType::RawMaterial)
            ?? $warehouses->firstWhere('is_quarantine', false)
            ?? $warehouses->first();

        $search = strtolower(trim((string) $request->query('search', '')));
        $levelFilter = (string) $request->query('level', '');

        $rows = collect();
        $counts = array_fill_keys(array_map(fn (StockAlertLevel $l) => $l->value, StockAlertLevel::cases()), 0);

        if ($selected !== null) {
            $rows = $this->balances->summaryForWarehouse($selected)
                ->map(function (array $row): array {
                    $level = $this->alerts->levelFor($row['item'], $row['on_hand']);

                    return [
                        'item_id' => $row['item']->id,
                        'code' => $row['item']->code,
                        'name' => $row['item']->name,
                        'type' => $row['item']->type->value,
                        'uom' => $row['item']->stockUom?->code,
                        'display_scale' => $row['item']->stockUom?->display_scale ?? 3,
                        'on_hand' => (string) $row['on_hand'],
                        'reserved' => (string) $row['reserved'],
                        'available' => (string) $row['available'],
                        'reorder_level' => $row['item']->reorder_level,
                        'minimum_stock' => $row['item']->minimum_stock,
                        'level' => $level->value,
                        'level_label' => $level->shortLabel(),
                        'severity' => $level->severity(),
                    ];
                });

            foreach ($rows as $row) {
                $counts[$row['level']]++;
            }

            if ($search !== '') {
                $rows = $rows->filter(fn (array $r) => str_contains(strtolower($r['code'].' '.$r['name']), $search));
            }

            if ($levelFilter !== '' && $levelFilter !== 'all') {
                $rows = $rows->filter(fn (array $r) => $r['level'] === $levelFilter);
            }

            $rows = $rows->sortBy([['severity', 'desc'], ['name', 'asc']])->values();
        }

        $expiringDays = (int) config('erp.stock_alerts.expiry_warning_days', 90);

        return Inertia::render('stock/index', [
            'warehouses' => $warehouses,
            'selected' => $selected,
            'rows' => $rows,
            'counts' => $counts,
            'levels' => array_map(fn (StockAlertLevel $l) => [
                'value' => $l->value, 'label' => $l->shortLabel(), 'variant' => $l->badgeVariant(), 'severity' => $l->severity(),
            ], StockAlertLevel::cases()),
            'filters' => ['search' => $search, 'level' => $levelFilter ?: 'all'],
            'expiring' => [
                'days' => $expiringDays,
                'count' => $selected === null ? 0 : InventoryLot::query()
                    ->releasable()
                    ->expiringWithin($expiringDays)
                    ->whereHas('balances', fn ($q) => $q->where('warehouse_id', $selected->id)->where('on_hand', '>', 0))
                    ->count(),
            ],
        ]);
    }
}
