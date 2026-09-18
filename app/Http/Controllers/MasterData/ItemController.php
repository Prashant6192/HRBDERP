<?php

declare(strict_types=1);

namespace App\Http\Controllers\MasterData;

use App\Domain\Contract\Models\Client;
use App\Domain\Intelligence\Services\StockOutlookService;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\Item;
use App\Domain\MasterData\Models\ItemCategory;
use App\Domain\MasterData\Services\ItemCodeGenerator;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Procurement\Contracts\InvoiceReader;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Procurement\Models\GoodsReceiptLine;
use App\Domain\Procurement\Services\BillReader;
use App\Domain\Quality\Models\QcInspection;
use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\StoreItemRequest;
use App\Http\Requests\MasterData\UpdateItemRequest;
use App\Support\Math\Decimal;
use App\Support\Tables\TableQuery;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shared behaviour for the three item modules.
 *
 * Raw materials, packaging materials and products are maintained by different
 * people under different permissions, but the mechanics of listing, creating
 * and editing them are identical. Subclasses supply the type, the model, and
 * the route and page names; everything else happens here once.
 */
abstract class ItemController extends Controller
{
    /**
     * @var list<string>
     */
    protected const SORTABLE = ['code', 'name', 'standard_cost', 'is_active', 'created_at'];

    abstract protected function itemType(): ItemType;

    /**
     * @return class-string<Item>
     */
    abstract protected function modelClass(): string;

    /**
     * Route name prefix, e.g. 'raw-materials'.
     */
    abstract protected function routeName(): string;

    /**
     * Inertia page directory, e.g. 'raw-materials'.
     */
    abstract protected function pageDirectory(): string;

    /**
     * The route parameter name Laravel binds for this module.
     */
    abstract protected function routeParameter(): string;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', $this->modelClass());

        $table = TableQuery::fromRequest($request, allowedFilters: ['category', 'status']);

        $query = $this->modelClass()::query()
            ->with('category:id,name', 'stockUom:id,code,display_scale')
            ->search($table->search);

        if ($category = $table->filter('category')) {
            $query->where('category_id', $category);
        }

        if ($status = $table->filter('status')) {
            $query->where('is_active', $status === 'active');
        }

        return Inertia::render("{$this->pageDirectory()}/index", [
            'items' => $table->paginate(
                $table->applySorting($query, static::SORTABLE, fallback: 'code')
            ),
            'table' => $table->toArray(),
            'categories' => $this->categoryOptions(),
            'itemType' => $this->itemType()->value,
            'can' => [
                'create' => $request->user()->can('create', $this->modelClass()),
                'export' => $request->user()->can($this->itemType()->permissionModule().'.export'),
                'import' => $request->user()->can($this->itemType()->permissionModule().'.import'),
            ],
            // Materials arrive with a supplier's bill: it can be uploaded from
            // here, read, matched to these masters, and booked in.
            'bill' => [
                'upload' => in_array($this->itemType(), [ItemType::RawMaterial, ItemType::PackagingMaterial], true)
                    && $request->user()->can('create', GoodsReceipt::class),
                'reader' => app(InvoiceReader::class)->available(),
                'photos' => ($reader = app(InvoiceReader::class)) instanceof BillReader ? $reader->readsPhotos() : $reader->available(),
            ],
        ]);
    }

    /**
     * A material added without leaving the goods receipt: what the bill
     * says now, the rest on the material's own page later. The code is
     * the next free one unless a code is given.
     */
    public function quick(Request $request, ItemCodeGenerator $codes): JsonResponse
    {
        $this->authorize('create', $this->modelClass());

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:64', Rule::unique('items', 'code')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'],
            'inci_name' => ['nullable', 'string', 'max:255'],
            'hsn_code' => ['nullable', 'string', 'max:16'],
            'stock_uom_id' => ['required', 'integer', Rule::exists('uoms', 'id')],
            'requires_qc' => ['boolean'],
            'shelf_life_days' => ['nullable', 'integer', 'min:1', 'max:36500'],
            'reorder_level' => ['required', 'numeric', 'gt:0'],
            'minimum_stock' => ['required', 'numeric', 'gte:0', 'lte:reorder_level'],
            'standard_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $item = $this->modelClass()::create([
            ...$data,
            'code' => trim((string) ($data['code'] ?? '')) !== '' ? strtoupper(trim($data['code'])) : $codes->next($this->itemType()),
            'requires_qc' => $data['requires_qc'] ?? true,
            'is_batch_tracked' => true,
            'is_active' => true,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        $item->load('stockUom:id,code');

        return response()->json([
            'value' => $item->id,
            'label' => "{$item->code} — {$item->name}",
            'type' => $item->type->value,
            'stock_uom_id' => $item->stock_uom_id,
            'stock_uom' => $item->stockUom?->code,
            'requires_qc' => $item->requires_qc,
            'shelf_life_days' => $item->shelf_life_days,
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', $this->modelClass());

        return Inertia::render("{$this->pageDirectory()}/create", $this->formOptions());
    }

    public function store(StoreItemRequest $request): RedirectResponse
    {
        $this->authorize('create', $this->modelClass());

        $item = $this->modelClass()::create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "{$item->code} created.",
        ]);

        return to_route("{$this->routeName()}.index");
    }

    public function show(Request $request, Item $item): Response
    {
        $this->authorize('view', $item);

        $item->load([
            'category:id,name',
            'client:id,code,name',
            'stockUom:id,code,name,display_scale',
            'purchaseUom:id,code,name',
            'netContentUom:id,code,name',
            'uomConversions.fromUom:id,code',
            'uomConversions.toUom:id,code',
            'createdBy:id,name',
            'updatedBy:id,name',
        ]);

        return Inertia::render("{$this->pageDirectory()}/show", [
            'item' => $item,
            'can' => [
                'update' => $request->user()->can('update', $item),
                'delete' => $request->user()->can('delete', $item),
                'receive' => $request->user()->can('create', GoodsReceipt::class),
                'view_stock' => $request->user()->can('inventory.view'),
                'view_qc' => $request->user()->can('qc.view'),
                'purchase' => $request->user()->can('purchase.view'),
            ],
            'stock' => $this->stockSummary($item),
            // Every batch of this material ever booked in, with where it came
            // from, what QC said, and what it cost.
            'batches' => $request->user()->can('inventory.view') ? $this->batchHistory($item) : null,
            // Where the material is heading, for those who may see stock.
            'outlook' => $request->user()->can('inventory.view') && in_array($item->type, [ItemType::RawMaterial, ItemType::PackagingMaterial], true)
                ? app(StockOutlookService::class)->forItem($item)->toArray()
                : null,
            ...$this->extraShowProps($item),
        ]);
    }

    public function edit(Request $request, Item $item): Response
    {
        $this->authorize('update', $item);

        return Inertia::render("{$this->pageDirectory()}/edit", [
            'item' => $item,
            ...$this->formOptions(),
        ]);
    }

    public function update(UpdateItemRequest $request, Item $item): RedirectResponse
    {
        $this->authorize('update', $item);

        $item->update([
            ...$request->validated(),
            'updated_by' => $request->user()->id,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "{$item->code} updated.",
        ]);

        return to_route("{$this->routeName()}.show", $item);
    }

    public function destroy(Request $request, Item $item): RedirectResponse
    {
        $this->authorize('delete', $item);

        $item->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "{$item->code} removed.",
        ]);

        return to_route("{$this->routeName()}.index");
    }

    /**
     * Props only one item type's screen needs.
     *
     * @return array<string, mixed>
     */
    protected function extraShowProps(Item $item): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formOptions(): array
    {
        return [
            'categories' => $this->categoryOptions(),
            'uoms' => $this->uomOptions(),
            'itemType' => $this->itemType()->value,
            // A product may be made for a third-party client.
            'clients' => Client::query()->active()->orderBy('name')->get(['id', 'code', 'name'])
                ->map(static fn (Client $c): array => ['value' => $c->id, 'label' => "{$c->name} ({$c->code})"])->all(),
        ];
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    protected function categoryOptions(): array
    {
        return ItemCategory::query()
            ->active()
            ->where(fn ($query) => $query
                ->whereNull('item_type')
                ->orWhere('item_type', $this->itemType()->value))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (ItemCategory $category): array => [
                'value' => $category->id,
                'label' => $category->name,
            ])
            ->all();
    }

    /**
     * @return list<array{value: int, label: string, dimension: string, requires_item_factor: bool}>
     */
    protected function uomOptions(): array
    {
        return Uom::query()
            ->active()
            ->orderBy('dimension')
            ->orderBy('code')
            ->get()
            ->map(static fn (Uom $uom): array => [
                'value' => $uom->id,
                'label' => "{$uom->code} — {$uom->name}",
                'dimension' => $uom->dimension->value,
                'requires_item_factor' => $uom->requires_item_factor,
            ])
            ->all();
    }

    /**
     * Where the item's stock stands right now: released and free in the
     * stores, held in quarantine, and the inspections still to decide — so
     * the person on the item screen can see what is waiting at QC and send
     * a fresh delivery there.
     *
     * @return array{on_hand: string, available: string, in_quarantine: string, awaiting_qc: list<array{id: int, number: string, batch: string|null, quantity: string, status: string}>}
     */
    /**
     * The batch history of one material: every lot, newest first, with its
     * supplier, QC decision, dates, cost and what is left of it.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, received_value: string}
     */
    private function batchHistory(Item $item): array
    {
        $lots = InventoryLot::query()
            ->where('item_id', $item->id)
            ->with(['vendor:id,code,name', 'ownerClient:id,code,name', 'qcDecidedBy:id,name', 'balances.warehouse:id,code,name,is_quarantine'])
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        // The goods receipt a lot came off, so the row can link back to the bill.
        $receiptLineIds = $lots->where('source_type', (new GoodsReceiptLine)->getMorphClass())->pluck('source_id')->filter();
        $receipts = $receiptLineIds->isEmpty()
            ? collect()
            : GoodsReceiptLine::query()->whereIn('id', $receiptLineIds)->with('receipt:id,number,invoice_ref')->get()->keyBy('id');

        $value = BigDecimal::zero();

        $rows = $lots->map(function (InventoryLot $lot) use ($receipts, &$value): array {
            $onHand = $lot->balances->reduce(fn (BigDecimal $c, StockBalance $b) => $c->plus(BigDecimal::of($b->on_hand)), BigDecimal::zero());
            $cost = $lot->unit_cost === null ? null : BigDecimal::of($lot->unit_cost);

            if ($cost !== null) {
                $value = $value->plus($cost->multipliedBy(BigDecimal::of($lot->initial_quantity)));
            }

            $line = $receipts->get($lot->source_id);
            $receipt = $lot->source_type === (new GoodsReceiptLine)->getMorphClass() ? $line?->receipt : null;

            return [
                'id' => $lot->id,
                'batch_number' => $lot->batch_number,
                'supplier_batch_ref' => $lot->supplier_batch_ref,
                // "Brand" on the shop floor: whose material it is. A client's
                // own stock is theirs; everything else is ours, under our
                // own brand, whoever supplied it.
                'brand' => $lot->ownerClient?->name ?? config('erp.company.brand'),
                'brand_kind' => $lot->ownerClient !== null ? 'client' : 'own',
                'supplier' => $lot->vendor?->name,
                'qc_status' => $lot->qc_status->value,
                'qc_status_label' => $lot->qc_status->label(),
                'qc_variant' => $lot->qc_status->badgeVariant(),
                'qc_decided_at' => $lot->qc_decided_at?->toIso8601String(),
                'qc_decided_by' => $lot->qcDecidedBy?->name,
                'manufactured_at' => $lot->manufactured_at?->toDateString(),
                'expiry_at' => $lot->expiry_at?->toDateString(),
                'received_at' => $lot->received_at?->toDateString(),
                'received_quantity' => Decimal::strip($lot->initial_quantity),
                'on_hand' => Decimal::strip($onHand),
                'unit_cost' => $cost === null ? null : (string) $cost->toScale(4, RoundingMode::HalfUp),
                'value' => $cost === null ? null : (string) $cost->multipliedBy(BigDecimal::of($lot->initial_quantity))->toScale(2, RoundingMode::HalfUp),
                'stores' => $lot->balances->filter(fn (StockBalance $b) => BigDecimal::of($b->on_hand)->isPositive())
                    ->map(fn (StockBalance $b) => ['code' => $b->warehouse?->code, 'name' => $b->warehouse?->name, 'quarantine' => (bool) $b->warehouse?->is_quarantine, 'quantity' => Decimal::strip($b->on_hand)])
                    ->values()->all(),
                'source' => $receipt !== null ? 'receipt' : ($lot->source_type === null ? 'opening' : 'production'),
                'receipt' => $receipt === null ? null : ['id' => $receipt->id, 'number' => $receipt->number, 'invoice_ref' => $receipt->invoice_ref],
                'expired' => $lot->isExpired(),
            ];
        })->values()->all();

        return [
            'rows' => $rows,
            'total' => InventoryLot::query()->where('item_id', $item->id)->count(),
            'received_value' => (string) $value->toScale(2, RoundingMode::HalfUp),
        ];
    }

    private function stockSummary(Item $item): array
    {
        $balances = StockBalance::query()
            ->with('warehouse:id,is_quarantine')
            ->where('item_id', $item->id)
            ->where('on_hand', '>', 0)
            ->get();

        $held = $balances->filter(fn (StockBalance $b) => (bool) $b->warehouse?->is_quarantine)
            ->reduce(fn ($c, StockBalance $b) => $c->plus($b->onHand()), BigDecimal::zero());
        $free = $balances->reject(fn (StockBalance $b) => (bool) $b->warehouse?->is_quarantine)
            ->reduce(fn ($c, StockBalance $b) => $c->plus($b->available()), BigDecimal::zero());
        $onHand = $balances->reduce(fn ($c, StockBalance $b) => $c->plus($b->onHand()), BigDecimal::zero());

        return [
            'on_hand' => (string) $onHand,
            'available' => (string) $free,
            'in_quarantine' => (string) $held,
            'awaiting_qc' => QcInspection::query()
                ->open()
                ->where('item_id', $item->id)
                ->with('lot:id,batch_number')
                ->orderBy('id')
                ->limit(10)
                ->get()
                ->map(fn (QcInspection $i) => [
                    'id' => $i->id,
                    'number' => $i->number,
                    'batch' => $i->lot?->batch_number,
                    'quantity' => $i->quantity,
                    'status' => $i->status->value,
                ])->all(),
        ];
    }
}
