<?php

declare(strict_types=1);

namespace App\Http\Controllers\MasterData;

use App\Domain\Contract\Models\Client;
use App\Domain\Intelligence\Services\StockOutlookService;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\Item;
use App\Domain\MasterData\Models\ItemCategory;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Quality\Models\QcInspection;
use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\StoreItemRequest;
use App\Http\Requests\MasterData\UpdateItemRequest;
use App\Support\Tables\TableQuery;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
