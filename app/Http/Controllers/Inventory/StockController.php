<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Enums\StockAlertLevel;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Services\StockAlertService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
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

        $facilities = Facility::query()->active()->ordered()->get(['id', 'code', 'name', 'can_manufacture']);
        $facilityId = $request->integer('facility') > 0 && $facilities->contains('id', $request->integer('facility')) ? $request->integer('facility') : null;

        $warehouses = Warehouse::query()->active()
            ->with('facility:id,code,name')
            ->when($facilityId !== null, fn ($q) => $q->where('facility_id', $facilityId))
            ->orderBy('is_quarantine')->orderBy('facility_id')->orderBy('sort_order')->orderBy('code')
            ->get(['id', 'code', 'name', 'type', 'is_quarantine', 'facility_id']);

        $selected = $request->integer('warehouse') > 0
            ? $warehouses->firstWhere('id', $request->integer('warehouse'))
            : null;

        // A store chosen by id says which facility we are looking at.
        if ($selected !== null && $facilityId === null) {
            $facilityId = $selected->facility_id;
        }

        // Without a facility, the store the day starts in is the
        // manufacturing facility's.
        if ($selected === null && $facilityId === null) {
            $facilityId = $facilities->firstWhere('can_manufacture', true)?->id ?? $facilities->first()?->id;
        }

        $inFacility = $warehouses->filter(fn (Warehouse $w) => $facilityId === null || $w->facility_id === $facilityId)->values();

        // The navigation names stores by what they hold, not by id.
        if ($selected === null && ($storeType = WarehouseType::tryFrom((string) $request->query('store'))) !== null) {
            $selected = $inFacility->first(fn (Warehouse $w) => ! $w->is_quarantine && $w->type === $storeType);
        }

        // Land on the raw material store by default: it is where the day starts.
        $selected ??= $inFacility->first(fn (Warehouse $w) => ! $w->is_quarantine && $w->type === WarehouseType::RawMaterial)
            ?? $inFacility->firstWhere('is_quarantine', false)
            ?? $inFacility->first();

        $search = strtolower(trim((string) $request->query('search', '')));
        $levelFilter = (string) $request->query('level', '');

        $rows = collect();
        $counts = array_fill_keys(array_map(fn (StockAlertLevel $l) => $l->value, StockAlertLevel::cases()), 0);

        if ($selected !== null) {
            $rows = $this->balances->summaryForWarehouse($selected)
                ->map(function (array $row) use ($selected): array {
                    $level = $this->alerts->levelAt($row['item'], $selected, $row['on_hand']);

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
            'facilities' => $facilities->map(fn (Facility $f) => ['id' => $f->id, 'code' => $f->code, 'name' => $f->name])->all(),
            'facility' => $facilityId,
            'warehouses' => $warehouses->map(fn (Warehouse $w) => [
                'id' => $w->id, 'code' => $w->code, 'name' => $w->name, 'type' => $w->type->value, 'is_quarantine' => $w->is_quarantine,
                'facility_id' => $w->facility_id, 'facility' => $w->facility?->name,
            ])->all(),
            'selected' => $selected === null ? null : [
                'id' => $selected->id, 'code' => $selected->code, 'name' => $selected->name, 'type' => $selected->type->value, 'is_quarantine' => $selected->is_quarantine,
                'facility_id' => $selected->facility_id, 'facility' => $selected->facility?->name,
            ],
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
