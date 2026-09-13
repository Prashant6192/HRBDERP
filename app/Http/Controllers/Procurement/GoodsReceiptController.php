<?php

declare(strict_types=1);

namespace App\Http\Controllers\Procurement;

use App\Domain\Contract\Models\Client;
use App\Domain\MasterData\Models\Item;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Planning\Models\MaterialRequest;
use App\Domain\Planning\Models\MaterialRequestLine;
use App\Domain\Procurement\Contracts\InvoiceReader;
use App\Domain\Procurement\Enums\GoodsReceiptStatus;
use App\Domain\Procurement\Exceptions\InvoiceIntakeException;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Procurement\Models\Vendor;
use App\Domain\Procurement\Services\GoodsReceiptService;
use App\Domain\Procurement\Services\InvoiceIntakeService;
use App\Domain\Warehousing\Models\Warehouse;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Procurement\IntakeInvoiceRequest;
use App\Http\Requests\Procurement\StoreGoodsReceiptRequest;
use App\Support\Tables\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class GoodsReceiptController extends Controller
{
    /**
     * @var list<string>
     */
    private const SORTABLE = ['number', 'received_at', 'status', 'created_at'];

    public function __construct(
        private readonly GoodsReceiptService $receipts,
        private readonly FacilityAccess $access,
        private readonly InvoiceIntakeService $intake,
        private readonly InvoiceReader $reader,
    ) {}

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

    public function create(Request $request): Response
    {
        $this->authorize('create', GoodsReceipt::class);

        $intake = $this->intake->find($request->query('intake'));

        return Inertia::render('goods-receipts/create', [
            // The bill just uploaded, read and matched — or nothing.
            'intake' => $intake,
            'reader' => [
                'available' => $this->reader->available(),
                'model' => config('erp.ai.model'),
            ],
            'can' => [
                // Keying lines by hand, or changing what the reader found.
                'manual' => $request->user()->can('enterManually', GoodsReceipt::class),
            ],
            'vendors' => Vendor::query()->purchasable()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Vendor $v) => ['value' => $v->id, 'label' => $v->name])->all(),
            // Who owns what is being received: us, or a client who sent it.
            'clients' => Client::query()->active()->orderBy('name')->get(['id', 'code', 'name'])
                ->map(fn (Client $c) => ['value' => $c->id, 'label' => "{$c->name} ({$c->code})"])->all(),
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
            // Opened from an item's page: the first line is that item.
            'presetItem' => request()->integer('item') ?: null,
            'presetWarehouse' => request()->integer('warehouse') ?: null,
        ]);
    }

    public function store(StoreGoodsReceiptRequest $request): RedirectResponse
    {
        $this->authorize('create', GoodsReceipt::class);

        $data = $request->validated();
        $this->access->assertCanWorkIn($request->user(), Warehouse::query()->findOrFail($data['warehouse_id']));

        $intake = $this->intake->find($data['intake_token'] ?? null);

        // Without the right to key a receipt by hand, the receipt must come
        // off a scanned bill and its particulars are the bill's.
        if (! $request->user()->can('enterManually', GoodsReceipt::class)) {
            if ($intake === null) {
                return back()->withInput()->withErrors(['intake_token' => 'Upload the supplier\'s bill; receipts are booked from the scan. Only the plant head or an administrator may key one in by hand.']);
            }

            $data['lines'] = $this->linesFromIntake($intake, $data['lines']);

            if ($data['lines'] === []) {
                return back()->withInput()->withErrors(['lines' => 'Choose the item for at least one line of the bill.']);
            }
        }

        $receipt = $this->receipts->create(
            attributes: $data,
            lines: $data['lines'],
            userId: $request->user()->id,
        );

        if ($intake !== null) {
            $receipt->forceFill([
                'entry_mode' => 'scan',
                'invoice_path' => $intake['path'],
                'invoice_name' => $intake['name'],
                'invoice_mime' => $intake['mime'],
                'extraction' => $intake['extraction'],
                'extraction_model' => $intake['extraction']['model'] ?? null,
                'extracted_at' => now(),
            ])->save();

            $this->intake->forget($intake['token']);
        }

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

    /**
     * Upload the supplier's bill: it is kept, read, matched to the vendor
     * and the items, and the receipt form opens filled in from it.
     */
    public function intake(IntakeInvoiceRequest $request): RedirectResponse
    {
        $this->authorize('create', GoodsReceipt::class);

        try {
            $payload = $this->intake->intake($request->file('invoice'), $request->user()->id);
        } catch (InvoiceIntakeException $e) {
            return back()->withErrors(['invoice' => $e->getMessage()]);
        }

        $lines = count($payload['lines']);
        $matched = count(array_filter($payload['lines'], fn ($l) => $l['item_id'] !== null));

        return redirect()->route('goods-receipts.create', ['intake' => $payload['token']])
            ->withToast($lines === 0 ? 'warning' : 'success', $lines === 0
                ? 'The bill was read but no goods lines were found. Check the document.'
                : "Bill read: {$lines} line".($lines === 1 ? '' : 's').", {$matched} matched to items".($payload['vendor']['id'] ? ", vendor {$payload['vendor']['name']}" : ', vendor not on file').'.');
    }

    /**
     * The stored bill, shown inline.
     */
    public function document(GoodsReceipt $goodsReceipt): HttpResponse
    {
        $this->authorize('view', $goodsReceipt);

        if ($goodsReceipt->invoice_path === null || ! Storage::disk(InvoiceIntakeService::DISK)->exists($goodsReceipt->invoice_path)) {
            abort(404, 'No bill is attached to this receipt.');
        }

        return Storage::disk(InvoiceIntakeService::DISK)->response($goodsReceipt->invoice_path, $goodsReceipt->invoice_name, [
            'Content-Type' => $goodsReceipt->invoice_mime ?? 'application/octet-stream',
        ]);
    }

    public function show(Request $request, GoodsReceipt $goodsReceipt): Response
    {
        $this->authorize('view', $goodsReceipt);

        $goodsReceipt->load([
            'vendor:id,name,code', 'ownerClient:id,code,name', 'warehouse:id,code,name', 'receivedBy:id,name', 'createdBy:id,name',
            'lines.item:id,code,name,requires_qc,stock_uom_id', 'lines.item.stockUom:id,code',
            'lines.uom:id,code', 'lines.lot:id,batch_number,qc_status,expiry_at',
            'lines.inspection:id,number,status',
        ]);

        return Inertia::render('goods-receipts/show', [
            'receipt' => $goodsReceipt,
            'document' => $goodsReceipt->invoice_path === null ? null : [
                'name' => $goodsReceipt->invoice_name,
                'mime' => $goodsReceipt->invoice_mime,
                'url' => route('goods-receipts.document', $goodsReceipt),
                'model' => $goodsReceipt->extraction_model,
                'extracted_at' => $goodsReceipt->extracted_at?->toIso8601String(),
                'invoice_number' => $goodsReceipt->extraction['invoice_number'] ?? null,
                'invoice_date' => $goodsReceipt->extraction['invoice_date'] ?? null,
                'total' => $goodsReceipt->extraction['total'] ?? null,
                'warnings' => $goodsReceipt->extraction['warnings'] ?? [],
            ],
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
        $this->access->assertCanWorkIn($request->user(), $goodsReceipt->warehouse);

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
     * For someone who may not key particulars: the bill's lines, with only
     * the item mapping and the unit taken from what they chose on screen.
     *
     * @param  array<string, mixed>  $intake
     * @param  list<array<string, mixed>>  $posted
     * @return list<array<string, mixed>>
     */
    private function linesFromIntake(array $intake, array $posted): array
    {
        $lines = [];
        $chosenByBillLine = [];

        // The screen says which bill line each of its lines came from; a
        // freight or rounding-off line has no quantity and is never shown.
        foreach ($posted as $position => $line) {
            $chosenByBillLine[(int) ($line['intake_index'] ?? $position)] = $line;
        }

        foreach ($intake['lines'] as $i => $read) {
            $chosen = $chosenByBillLine[$i] ?? [];
            $itemId = $chosen['item_id'] ?? $read['item_id'];

            if ($itemId === null || $itemId === '') {
                continue;
            }

            $lines[] = [
                'item_id' => (int) $itemId,
                'quantity' => $read['quantity'] ?? $chosen['quantity'] ?? '0',
                'uom_id' => (int) ($chosen['uom_id'] ?? $read['uom_id'] ?? 0),
                'unit_price' => $read['rate'],
                'supplier_batch_ref' => $read['batch'],
                'manufactured_at' => $read['manufactured_at'],
                'expiry_at' => $read['expiry_at'] ?? ($chosen['expiry_at'] ?? null),
                'notes' => $read['description'],
            ];
        }

        return $lines;
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
