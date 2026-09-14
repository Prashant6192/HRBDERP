<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Exceptions\OpeningStockException;
use App\Domain\Inventory\Models\InventoryTransaction;
use App\Domain\Inventory\Services\OpeningStockService;
use App\Domain\Inventory\Services\OpeningStockSheetService;
use App\Domain\MasterData\Models\Item;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Old stock, entered once.
 *
 * The stock the factory already holds when the ERP goes live — raw
 * materials, packaging and finished goods — booked from one screen, by
 * hand or from a filled-in sheet, into the right store of the facility,
 * released without a QC round. Reserved for the system administrator.
 */
class OldStockController extends Controller
{
    public const array KINDS = [
        'raw_material' => ['label' => 'Raw materials', 'store' => WarehouseType::RawMaterial],
        'packaging' => ['label' => 'Packaging materials', 'store' => WarehouseType::Packaging],
        'finished_goods' => ['label' => 'Finished goods', 'store' => WarehouseType::FinishedGoods],
    ];

    public function __construct(
        private readonly OpeningStockService $opening,
        private readonly OpeningStockSheetService $sheets,
    ) {}

    public function index(Request $request): Response
    {
        $this->guard($request);

        $facilities = Facility::query()->active()->ordered()->get(['id', 'code', 'name', 'city', 'opening_stock_enabled', 'can_manufacture']);
        $facility = $request->integer('facility') > 0
            ? $facilities->firstWhere('id', $request->integer('facility'))
            : ($facilities->firstWhere('can_manufacture', true) ?? $facilities->first());

        $stores = $facility === null ? collect() : Warehouse::query()->atFacility($facility)->active()->where('is_system', false)->orderBy('sort_order')->orderBy('code')->get(['id', 'code', 'name', 'type']);

        $booked = $facility === null ? collect() : InventoryTransaction::query()
            ->where('type', InventoryTransactionType::OpeningBalance->value)
            ->whereIn('warehouse_id', $stores->pluck('id'))
            ->withCount('lines')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (InventoryTransaction $t) => ['id' => $t->id, 'number' => $t->number, 'store' => $stores->firstWhere('id', $t->warehouse_id)?->name, 'lines' => $t->lines_count, 'at' => $t->transacted_at?->toDateString()]);

        $items = Item::query()->active()->with('stockUom:id,code')->orderBy('name')->get(['id', 'code', 'name', 'type', 'stock_uom_id', 'shelf_life_days', 'standard_cost'])
            ->map(fn (Item $i) => ['value' => $i->id, 'label' => "{$i->name} ({$i->code})", 'type' => $i->type->value, 'uom_id' => $i->stock_uom_id, 'uom' => $i->stockUom?->code, 'standard_cost' => $i->standard_cost]);

        return Inertia::render('opening-stock/index', [
            'facilities' => $facilities->map(fn (Facility $f) => ['value' => $f->id, 'label' => "{$f->name} ({$f->code})", 'opening_stock_enabled' => $f->opening_stock_enabled])->values()->all(),
            'facility' => $facility?->only(['id', 'code', 'name', 'opening_stock_enabled']),
            'kinds' => collect(self::KINDS)->map(fn (array $k, string $key) => [
                'key' => $key,
                'label' => $k['label'],
                'stores' => $stores->where('type', $k['store'])->map(fn (Warehouse $w) => ['value' => $w->id, 'label' => "{$w->name} ({$w->code})"])->values()->all(),
                'items' => $items->whereIn('type', array_map(fn ($t) => $t->value, OpeningStockSheetService::typesFor($key)))->values()->all(),
            ])->values()->all(),
            'uoms' => Uom::query()->active()->orderBy('dimension')->orderBy('code')->get(['id', 'code', 'dimension'])
                ->map(fn (Uom $u) => ['value' => $u->id, 'label' => $u->code, 'dimension' => $u->dimension->value])->all(),
            'booked' => $booked->values()->all(),
            'today' => now()->toDateString(),
        ]);
    }

    public function template(Request $request, string $kind): HttpResponse
    {
        $this->guard($request);
        abort_unless(isset(self::KINDS[$kind]), 404);

        return response($this->sheets->template($kind), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="opening-stock-'.$kind.'.xlsx"',
        ]);
    }

    /**
     * A filled-in sheet, matched to the masters and handed back to the
     * screen to be looked over before anything is booked.
     */
    public function parse(Request $request): JsonResponse
    {
        $this->guard($request);

        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(self::KINDS))],
            'sheet' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv'],
        ]);

        try {
            $parsed = $this->sheets->parse($request->file('sheet')->getRealPath(), $data['kind']);
        } catch (OpeningStockException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($parsed);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->guard($request);

        $data = $request->validate([
            'facility_id' => ['required', 'integer', Rule::exists('facilities', 'id')->where('is_active', true)],
            'as_of' => ['nullable', 'date', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'sections' => ['required', 'array'],
            'sections.*.kind' => ['required', Rule::in(array_keys(self::KINDS))],
            'sections.*.warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where(fn ($q) => $q->where('facility_id', (int) $request->input('facility_id'))->where('is_active', true)->where('is_system', false)->whereNull('deleted_at'))],
            'sections.*.lines' => ['present', 'array'],
            'sections.*.lines.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')->whereNull('deleted_at')],
            'sections.*.lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'sections.*.lines.*.uom_id' => ['nullable', 'integer', Rule::exists('uoms', 'id')],
            'sections.*.lines.*.batch_number' => ['nullable', 'string', 'max:64'],
            'sections.*.lines.*.manufactured_at' => ['nullable', 'date'],
            'sections.*.lines.*.expiry_at' => ['nullable', 'date'],
            'sections.*.lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'sections.*.lines.*.remarks' => ['nullable', 'string', 'max:255'],
        ], [
            'sections.*.lines.*.item_id.required' => 'Choose the material on every line.',
            'sections.*.lines.*.quantity.gt' => 'Each quantity must be greater than zero.',
            'as_of.before_or_equal' => 'Opening stock cannot be dated in the future.',
        ]);

        $byStore = [];

        foreach ($data['sections'] as $i => $section) {
            if ($section['lines'] === []) {
                continue;
            }

            if (empty($section['warehouse_id'])) {
                return back()->withInput()->withErrors(["sections.{$i}.warehouse_id" => 'Choose the store these lines go into.']);
            }

            $store = Warehouse::query()->findOrFail($section['warehouse_id']);
            $kind = self::KINDS[$section['kind']];

            if ($store->type !== $kind['store'] && $store->type !== WarehouseType::General) {
                return back()->withInput()->withErrors(["sections.{$i}.warehouse_id" => "{$store->name} is not a {$kind['store']->label()} store."]);
            }

            $byStore[$store->id] = [...($byStore[$store->id] ?? []), ...$section['lines']];
        }

        try {
            $postings = $this->opening->bookMany($byStore, $request->user()->id, $data['as_of'] ?? null, $data['remarks'] ?? null);
        } catch (OpeningStockException|RuntimeException $e) {
            return back()->withInput()->withErrors(['sections' => $e->getMessage()]);
        }

        $lines = array_sum(array_map(fn (array $l) => count($l), $byStore));
        $numbers = implode(', ', array_map(fn (InventoryTransaction $t) => $t->number, $postings));

        return redirect()->route('opening-stock.index', ['facility' => $data['facility_id']])
            ->withToast('success', "Old stock booked: {$lines} line".($lines === 1 ? '' : 's').' in '.count($postings).' posting'.(count($postings) === 1 ? '' : 's')." ({$numbers}). QC is marked passed; the stock shows in its store now.");
    }

    private function guard(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Old stock entry is reserved for the system administrator.');
    }
}
