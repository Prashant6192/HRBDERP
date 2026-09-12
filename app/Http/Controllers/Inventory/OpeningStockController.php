<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Exceptions\OpeningStockException;
use App\Domain\Inventory\Services\OpeningStockService;
use App\Domain\MasterData\Models\Item;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreOpeningStockRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Booking what a facility already holds on the day it goes live.
 */
class OpeningStockController extends Controller
{
    public function __construct(
        private readonly OpeningStockService $opening,
        private readonly FacilityAccess $access,
    ) {}

    public function create(Request $request, Facility $facility): Response
    {
        $this->authorize('bookOpeningStock', $facility);
        $this->access->assertCanWorkAt($request->user(), $facility);

        $stores = $this->access->scopeStores($request->user(), Warehouse::query()->atFacility($facility)->active()->with('category:id,badge,name,kind')->orderBy('sort_order')->orderBy('code'))
            ->get()
            ->map(fn (Warehouse $w) => ['value' => $w->id, 'label' => "{$w->name} ({$w->code})", 'badge' => $w->badge(), 'type' => $w->type->value]);

        return Inertia::render('facilities/opening-stock', [
            'facility' => $facility->only(['id', 'code', 'name', 'opening_stock_enabled', 'is_active']),
            'stores' => $stores->values()->all(),
            'preset_store' => $request->integer('store') ?: null,
            'items' => Item::query()->active()->with('stockUom:id,code')->orderBy('name')->get(['id', 'code', 'name', 'type', 'stock_uom_id', 'shelf_life_days', 'standard_cost'])
                ->map(fn (Item $i) => [
                    'value' => $i->id, 'label' => "{$i->name} ({$i->code})", 'type' => $i->type->value,
                    'uom_id' => $i->stock_uom_id, 'uom' => $i->stockUom?->code, 'standard_cost' => $i->standard_cost,
                ])->all(),
            'uoms' => Uom::query()->active()->orderBy('dimension')->orderBy('code')->get(['id', 'code', 'name', 'dimension'])
                ->map(fn (Uom $u) => ['value' => $u->id, 'label' => $u->code, 'dimension' => $u->dimension->value])->all(),
            'today' => now()->toDateString(),
        ]);
    }

    public function store(StoreOpeningStockRequest $request, Facility $facility): RedirectResponse
    {
        $this->authorize('bookOpeningStock', $facility);

        $data = $request->validated();
        $store = Warehouse::query()->findOrFail($data['warehouse_id']);
        $this->access->assertCanWorkIn($request->user(), $store);

        try {
            $transaction = $this->opening->book($store, $data['lines'], $request->user()->id, $data['as_of'] ?? null, $data['remarks'] ?? null);
        } catch (OpeningStockException|RuntimeException $e) {
            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }

        $count = count($data['lines']);

        return redirect()->route('stores.show', $store)
            ->withToast('success', "Opening stock booked: {$count} line".($count === 1 ? '' : 's')." posted as {$transaction->number}.");
    }
}
