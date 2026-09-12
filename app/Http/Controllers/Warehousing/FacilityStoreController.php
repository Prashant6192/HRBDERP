<?php

declare(strict_types=1);

namespace App\Http\Controllers\Warehousing;

use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\InventoryTransactionLine;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Services\StockAlertService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Warehousing\Exceptions\FacilityException;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Domain\Warehousing\Services\StoreService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Warehousing\StoreStoreRequest;
use App\Http\Requests\Warehousing\UpdateStoreRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One store inside a facility: its stock, its batches, its movements, and
 * the few things that can be done to it.
 */
class FacilityStoreController extends Controller
{
    public function __construct(
        private readonly StoreService $stores,
        private readonly StockBalanceService $balances,
        private readonly StockAlertService $alerts,
        private readonly FacilityAccess $access,
    ) {}

    public function store(StoreStoreRequest $request, Facility $facility): RedirectResponse
    {
        $this->authorize('create', Warehouse::class);
        $this->authorize('update', $facility);

        try {
            $store = $this->stores->create($facility, $request->validated(), $request->user()->id);
        } catch (FacilityException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return back()->withToast('success', "Store {$store->code} added to {$facility->name}.");
    }

    public function show(Request $request, Warehouse $warehouse): Response
    {
        $this->authorize('view', $warehouse);

        $warehouse->load(['facility:id,code,name,is_active,opening_stock_enabled,can_manufacture', 'category:id,badge,name,kind,color', 'manager:id,name', 'itemLevels', 'locations' => fn ($q) => $q->orderBy('code')]);
        $user = $request->user();

        $rows = $this->balances->summaryForWarehouse($warehouse)->map(function (array $row) use ($warehouse): array {
            $level = $this->alerts->levelAt($row['item'], $warehouse, $row['on_hand']);
            $thresholds = $this->alerts->thresholdsFor($row['item'], $warehouse);

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
                'reorder_level' => $thresholds['reorder']?->__toString(),
                'minimum_stock' => $thresholds['minimum']?->__toString(),
                'store_specific' => $thresholds['store_specific'],
                'level' => $level->value,
                'level_label' => $level->shortLabel(),
                'severity' => $level->severity(),
            ];
        })->sortBy([['severity', 'desc'], ['name', 'asc']])->values()->all();

        $lots = InventoryLot::query()
            ->with(['item:id,code,name'])
            ->whereHas('balances', fn ($q) => $q->where('warehouse_id', $warehouse->id)->where('on_hand', '>', 0))
            ->withSum(['balances as on_hand' => fn ($q) => $q->where('warehouse_id', $warehouse->id)], 'on_hand')
            ->orderBy('expiry_at')
            ->limit(100)
            ->get()
            ->map(fn (InventoryLot $l) => [
                'id' => $l->id, 'batch_number' => $l->batch_number, 'item' => $l->item?->name, 'item_code' => $l->item?->code,
                'qc_status' => $l->qc_status->value, 'expiry_at' => $l->expiry_at?->toDateString(), 'on_hand' => (string) $l->on_hand,
            ])->all();

        $movements = InventoryTransactionLine::query()
            ->with(['transaction:id,number,type,transacted_at,reason', 'item:id,code,name', 'lot:id,batch_number'])
            ->where('warehouse_id', $warehouse->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (InventoryTransactionLine $line) => [
                'id' => $line->id,
                'number' => $line->transaction?->number,
                'type' => $line->transaction?->type->label(),
                'item' => $line->item?->name,
                'batch' => $line->lot?->batch_number,
                'quantity' => $line->quantity,
                'reason' => $line->transaction?->reason,
                'at' => $line->transaction?->transacted_at?->toIso8601String(),
            ])->all();

        return Inertia::render('stores/show', [
            'store' => [
                ...$warehouse->only(['id', 'code', 'name', 'is_active', 'is_quarantine', 'notes', 'sort_order']),
                'type' => $warehouse->type->value,
                'badge' => $warehouse->badge(),
                'category' => $warehouse->category?->name,
                'category_id' => $warehouse->store_category_id,
                'manager' => $warehouse->manager?->name,
                'manager_id' => $warehouse->manager_id,
                'facility' => $warehouse->facility ? ['id' => $warehouse->facility->id, 'code' => $warehouse->facility->code, 'name' => $warehouse->facility->name] : null,
                'locations' => $warehouse->locations->map(fn ($l) => ['id' => $l->id, 'code' => $l->code, 'name' => $l->name, 'type' => $l->type, 'is_active' => $l->is_active])->all(),
                'has_history' => $warehouse->hasOperationalHistory(),
            ],
            'rows' => $rows,
            'lots' => $lots,
            'movements' => $movements,
            'openTransfers' => StockTransfer::query()->open()
                ->where(fn ($q) => $q->where('source_warehouse_id', $warehouse->id)->orWhere('destination_warehouse_id', $warehouse->id))
                ->orderByDesc('id')->limit(10)->get(['id', 'number', 'status', 'source_warehouse_id', 'destination_warehouse_id'])
                ->map(fn (StockTransfer $t) => ['id' => $t->id, 'number' => $t->number, 'status_label' => $t->status->label(), 'tone' => $t->status->tone(), 'direction' => $t->source_warehouse_id === $warehouse->id ? 'out' : 'in'])->all(),
            'openReceipts' => GoodsReceipt::query()->where('warehouse_id', $warehouse->id)->where('status', 'draft')->count(),
            'can' => [
                'update' => $user->can('update', $warehouse),
                'deactivate' => $user->can('deactivate', $warehouse),
                'delete' => $user->can('delete', $warehouse),
                'work_here' => $this->access->canWorkIn($user, $warehouse),
                'receive' => $user->can('purchase.receive') && $this->access->canWorkIn($user, $warehouse),
                'transfer' => $user->can('create', StockTransfer::class) && $this->access->canWorkIn($user, $warehouse),
                'opening_stock' => $warehouse->facility !== null && $warehouse->facility->opening_stock_enabled && $user->can('inventory.opening_stock') && $this->access->canWorkIn($user, $warehouse),
            ],
        ]);
    }

    public function update(UpdateStoreRequest $request, Warehouse $warehouse): RedirectResponse
    {
        $this->authorize('update', $warehouse);

        try {
            $this->stores->update($warehouse, $request->validated(), $request->user()->id);
        } catch (FacilityException $e) {
            return back()->withErrors(['name' => $e->getMessage()]);
        }

        return back()->withToast('success', "Store {$warehouse->code} updated.");
    }

    public function deactivate(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $this->authorize('deactivate', $warehouse);

        try {
            $this->stores->deactivate($warehouse, $request->user()->id);
        } catch (FacilityException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return back()->withToast('success', "Store {$warehouse->code} deactivated. Its history is kept.");
    }

    public function activate(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $this->authorize('deactivate', $warehouse);

        $this->stores->activate($warehouse, $request->user()->id);

        return back()->withToast('success', "Store {$warehouse->code} is active again.");
    }

    public function destroy(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $this->authorize('delete', $warehouse);

        try {
            $this->stores->delete($warehouse);
        } catch (FacilityException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        $target = $warehouse->facility_id ? route('facilities.show', [$warehouse->facility_id, 'tab' => 'stores']) : route('warehouses.index');

        return redirect($target)->withToast('success', "Store {$warehouse->code} removed.");
    }
}
