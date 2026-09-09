<?php

declare(strict_types=1);

namespace App\Http\Controllers\Procurement;

use App\Domain\MasterData\Models\Item;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Planning\Models\MaterialRequest;
use App\Domain\Planning\Models\MaterialRequestLine;
use App\Domain\Procurement\Enums\GoodsReceiptStatus;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Procurement\Models\Vendor;
use App\Domain\Procurement\Services\GoodsReceiptService;
use App\Domain\Warehousing\Models\Warehouse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Procurement\StoreGoodsReceiptRequest;
use App\Support\Tables\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class GoodsReceiptController extends Controller
{
    /**
     * @var list<string>
     */
    private const SORTABLE = ['number', 'received_at', 'status', 'created_at'];

    public function __construct(private readonly GoodsReceiptService $receipts) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', GoodsReceipt::class);

        $table = TableQuery::fromRequest($request, allowedFilters: ['status', 'warehouse']);

        $query = GoodsReceipt::query()
            ->with(['vendor:id,name', 'warehouse:id,code,name'])
            ->withCount('lines')
            ->search($table->search);

        if ($status = $table->filter('status')) {
            $query->where('status', $status);
        }

        if ($warehouse = $table->filter('warehouse')) {
            $query->where('warehouse_id', $warehouse);
        }

        return Inertia::render('goods-receipts/index', [
            'receipts' => $table->paginate($table->applySorting($query, self::SORTABLE, fallback: 'received_at')),
            'table' => $table->toArray(),
            'statuses' => array_map(fn (GoodsReceiptStatus $s) => ['value' => $s->value, 'label' => $s->label()], GoodsReceiptStatus::cases()),
            'warehouses' => $this->warehouseOptions(),
            'can' => ['create' => $request->user()->can('create', GoodsReceipt::class)],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', GoodsReceipt::class);

        return Inertia::render('goods-receipts/create', [
            'vendors' => Vendor::query()->purchasable()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Vendor $v) => ['value' => $v->id, 'label' => $v->name])->all(),
            'warehouses' => $this->warehouseOptions(),
            'items' => Item::query()->active()->with('stockUom:id,code')->orderBy('code')->get()
                ->map(fn (Item $i) => [
                    'value' => $i->id,
                    'label' => "{$i->code} — {$i->name}",
                    'type' => $i->type->value,
                    'stock_uom_id' => $i->stock_uom_id,
                    'stock_uom' => $i->stockUom?->code,
                    'requires_qc' => $i->requires_qc,
                    'shelf_life_days' => $i->shelf_life_days,
                ])->all(),
            'uoms' => Uom::query()->active()->where('requires_item_factor', false)->orderBy('dimension')->orderBy('code')->get()
                ->map(fn (Uom $u) => ['value' => $u->id, 'label' => $u->code, 'dimension' => $u->dimension->value])->all(),
            'today' => now()->toDateString(),

            // Open material requests a delivery may be booked in against;
            // choosing one pre-fills the store and the lines still to come.
            'materialRequests' => MaterialRequest::query()->open()
                ->with(['plan:id,number,formula_id', 'plan.formula:id,name', 'lines' => fn ($q) => $q->orderBy('line_no'), 'lines.item:id,code,name,stock_uom_id'])
                ->orderByDesc('requested_at')
                ->get()
                ->map(static fn (MaterialRequest $r): array => [
                    'value' => $r->id,
                    'label' => "{$r->number} · {$r->plan->formula->name} ({$r->store_kind->shortLabel()})",
                    'warehouse_id' => $r->warehouse_id,
                    'lines' => $r->lines->map(static fn (MaterialRequestLine $l): array => [
                        'item_id' => $l->item_id,
                        'uom_id' => $l->uom_id,
                        'outstanding' => $l->outstanding()->__toString(),
                        'required' => $l->required_quantity,
                    ])->all(),
                ])->all(),
            'selectedMaterialRequest' => request()->integer('material_request') ?: null,
        ]);
    }

    public function store(StoreGoodsReceiptRequest $request): RedirectResponse
    {
        $this->authorize('create', GoodsReceipt::class);

        $data = $request->validated();

        $receipt = $this->receipts->create(
            attributes: $data,
            lines: $data['lines'],
            userId: $request->user()->id,
        );

        if ($request->boolean('post_now')) {
            $this->authorize('post', $receipt);
            $receipt = $this->receipts->post($receipt, $request->user()->id);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $receipt->status === GoodsReceiptStatus::Received
                ? "{$receipt->number} received. Stock is now in quarantine awaiting QC where required."
                : "{$receipt->number} saved as a draft.",
        ]);

        return to_route('goods-receipts.show', $receipt);
    }

    public function show(Request $request, GoodsReceipt $goodsReceipt): Response
    {
        $this->authorize('view', $goodsReceipt);

        $goodsReceipt->load([
            'vendor:id,name,code', 'warehouse:id,code,name', 'receivedBy:id,name', 'createdBy:id,name',
            'lines.item:id,code,name,requires_qc,stock_uom_id', 'lines.item.stockUom:id,code',
            'lines.uom:id,code', 'lines.lot:id,batch_number,qc_status,expiry_at',
            'lines.inspection:id,number,status',
        ]);

        return Inertia::render('goods-receipts/show', [
            'receipt' => $goodsReceipt,
            'can' => [
                'post' => $goodsReceipt->status->isEditable() && $request->user()->can('post', $goodsReceipt),
                'cancel' => $goodsReceipt->status->isEditable() && $request->user()->can('cancel', $goodsReceipt),
                'viewQc' => $request->user()->can('qc.view'),
            ],
        ]);
    }

    public function post(Request $request, GoodsReceipt $goodsReceipt): RedirectResponse
    {
        $this->authorize('post', $goodsReceipt);

        try {
            $this->receipts->post($goodsReceipt, $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['receipt' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$goodsReceipt->number} posted. Batch numbers generated."]);

        return to_route('goods-receipts.show', $goodsReceipt);
    }

    public function cancel(GoodsReceipt $goodsReceipt): RedirectResponse
    {
        $this->authorize('cancel', $goodsReceipt);

        try {
            $this->receipts->cancel($goodsReceipt);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['receipt' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$goodsReceipt->number} cancelled."]);

        return to_route('goods-receipts.index');
    }

    /**
     * @return list<array{value: int, label: string, type: string}>
     */
    private function warehouseOptions(): array
    {
        return Warehouse::query()->availableForIssue()->orderBy('code')->get()
            ->map(fn (Warehouse $w) => ['value' => $w->id, 'label' => "{$w->code} — {$w->name}", 'type' => $w->type->value])
            ->all();
    }
}
