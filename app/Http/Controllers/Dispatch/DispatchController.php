<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dispatch;

use App\Domain\Dispatch\Enums\DispatchAttachmentKind;
use App\Domain\Dispatch\Enums\DispatchStatus;
use App\Domain\Dispatch\Exceptions\DispatchException;
use App\Domain\Dispatch\Models\Customer;
use App\Domain\Dispatch\Models\Dispatch;
use App\Domain\Dispatch\Models\DispatchAttachment;
use App\Domain\Dispatch\Models\DispatchLine;
use App\Domain\Dispatch\Services\DispatchChallanService;
use App\Domain\Dispatch\Services\DispatchService;
use App\Domain\Dispatch\Services\EInvoiceService;
use App\Domain\Dispatch\Support\GstStateCodes;
use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\Item;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dispatch\AttachDispatchDocumentRequest;
use App\Http\Requests\Dispatch\DispatchGoodsRequest;
use App\Http\Requests\Dispatch\RecordDispatchInvoiceRequest;
use App\Http\Requests\Dispatch\StoreDispatchRequest;
use App\Support\Tables\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Dispatch: finished goods leaving the factory against a tax invoice.
 * Write the consignment up, record the invoice and what the IRP returned,
 * let it go, mark it delivered — and see everything that ever left.
 */
class DispatchController extends Controller
{
    private const array SORTABLE = ['number', 'status', 'invoice_number', 'invoice_date', 'total_value', 'dispatched_at', 'created_at'];

    public function __construct(
        private readonly DispatchService $dispatches,
        private readonly EInvoiceService $einvoice,
        private readonly DispatchChallanService $challans,
        private readonly FacilityAccess $access,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Dispatch::class);

        $table = TableQuery::fromRequest($request, allowedFilters: ['status', 'customer', 'facility', 'period']);

        $filters = function (Builder $query) use ($table): Builder {
            $query->search($table->search);

            if ($status = $table->filter('status')) {
                $status === 'open' ? $query->open() : $query->where('status', $status);
            }

            if ($customer = $table->filter('customer')) {
                $query->where('customer_id', (int) $customer);
            }

            if ($facility = $table->filter('facility')) {
                $query->where('facility_id', (int) $facility);
            }

            if ($from = $this->periodStart($table->filter('period'))) {
                $query->whereRaw('COALESCE(dispatched_at, invoice_date, created_at) >= ?', [$from]);
            }

            return $query;
        };

        $query = $filters(Dispatch::query())
            ->with(['customer:id,code,name,kind', 'facility:id,code,name', 'warehouse:id,code,name', 'creator:id,name'])
            ->withCount('lines');

        $totals = $filters(Dispatch::query())
            ->where('status', '!=', DispatchStatus::Cancelled->value)
            ->selectRaw('COUNT(*) AS consignments, COALESCE(SUM(taxable_value), 0) AS taxable, COALESCE(SUM(cgst + sgst + igst), 0) AS tax, COALESCE(SUM(total_value), 0) AS total')
            ->first();

        $rows = $table->paginate($table->applySorting($query, self::SORTABLE, fallback: 'created_at')->orderByDesc('id'))
            ->through(fn (Dispatch $d): array => [
                'id' => $d->id,
                'number' => $d->number,
                'status' => $d->status->value,
                'status_label' => $d->status->label(),
                'status_tone' => $d->status->tone(),
                'customer' => $d->customer === null ? null : ['id' => $d->customer->id, 'code' => $d->customer->code, 'name' => $d->customer->name, 'kind' => $d->customer->kind->value],
                'facility' => $d->facility?->name,
                'store' => $d->warehouse?->code,
                'invoice_number' => $d->invoice_number,
                'invoice_date' => $d->invoice_date?->toDateString(),
                'has_irn' => $d->hasIrn(),
                'eway_bill_number' => $d->eway_bill_number,
                'vehicle_number' => $d->vehicle_number,
                'lines_count' => $d->lines_count,
                'total_value' => $d->total_value,
                'dispatched_at' => $d->dispatched_at?->toIso8601String(),
                'created_at' => $d->created_at?->toIso8601String(),
                'created_by' => $d->creator?->name,
            ]);

        return Inertia::render('dispatch/index', [
            'dispatches' => $rows,
            'table' => $table->toArray(),
            'summary' => [
                'consignments' => (int) ($totals->consignments ?? 0),
                'taxable' => (string) ($totals->taxable ?? '0'),
                'tax' => (string) ($totals->tax ?? '0'),
                'total' => (string) ($totals->total ?? '0'),
            ],
            'statuses' => [
                ['value' => 'open', 'label' => 'All open'],
                ...DispatchStatus::options(),
            ],
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Customer $c) => ['value' => (string) $c->id, 'label' => $c->name])->all(),
            'facilities' => Facility::query()->active()->ordered()->get(['id', 'name'])
                ->map(fn (Facility $f) => ['value' => (string) $f->id, 'label' => $f->name])->all(),
            'can' => [
                'create' => $request->user()->can('create', Dispatch::class),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Dispatch::class);

        $facilityIds = $this->access->scopeFacilities($request->user(), Facility::query()->active()->where('can_dispatch', true))->pluck('id');

        $stores = Warehouse::query()->availableForIssue()
            ->where('type', WarehouseType::FinishedGoods->value)
            ->whereIn('facility_id', $facilityIds)
            ->with('facility:id,code,name,legal_name,gstin,state')
            ->orderBy('facility_id')->orderBy('sort_order')->orderBy('code')
            ->get()
            ->map(function (Warehouse $w): array {
                $seller = $this->einvoice->seller($w->facility);

                return [
                    'id' => $w->id,
                    'code' => $w->code,
                    'name' => $w->name,
                    'facility_id' => $w->facility_id,
                    'facility' => $w->facility?->name,
                    'bills_as' => $seller['legal_name'],
                    'seller_gstin' => $seller['gstin'],
                    'seller_state_code' => $seller['state_code'],
                ];
            })->all();

        $customers = Customer::query()->active()->with('client:id,name')->orderBy('name')->get()
            ->map(fn (Customer $c): array => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'kind' => $c->kind->value,
                'kind_label' => $c->kind->label(),
                'gstin' => $c->gstin,
                'state_code' => $c->stateCode(),
                'client_id' => $c->client_id,
                'client' => $c->client?->name,
                'shipping' => [
                    'name' => $c->name,
                    'gstin' => $c->gstin,
                    'address_line_1' => $c->shipping_address_line_1 ?? $c->billing_address_line_1,
                    'address_line_2' => $c->shipping_address_line_2 ?? $c->billing_address_line_2,
                    'city' => $c->shipping_city ?? $c->billing_city,
                    'state' => $c->shipping_state ?? $c->billing_state,
                    'pincode' => $c->shipping_pincode ?? $c->billing_pincode,
                ],
            ])->all();

        $items = Item::query()->active()
            ->where('type', ItemType::FinishedGood->value)
            ->with(['stockUom:id,code', 'client:id,name'])
            ->orderBy('name')
            ->get()
            ->map(fn (Item $i): array => [
                'value' => $i->id,
                'label' => "{$i->code} — {$i->name}",
                'name' => $i->name,
                'hsn_code' => $i->hsn_code,
                'gst_rate' => $i->gst_rate,
                'mrp' => $i->mrp,
                'uom' => $i->stockUom?->code,
                'client_id' => $i->client_id,
                'client' => $i->client?->name,
            ])->all();

        return Inertia::render('dispatch/create', [
            'stores' => $stores,
            'customers' => $customers,
            'items' => $items,
            'states' => GstStateCodes::options(),
            'einvoiceMandatory' => (bool) config('erp.dispatch.einvoice_mandatory', true),
            'brand' => config('erp.company.brand'),
            'preset' => [
                'warehouse_id' => $request->integer('warehouse') ?: null,
                'customer_id' => $request->integer('customer') ?: null,
            ],
            'today' => now()->toDateString(),
        ]);
    }

    public function store(StoreDispatchRequest $request): RedirectResponse
    {
        $this->authorize('create', Dispatch::class);

        $data = $request->validated();
        $this->access->assertCanWorkIn($request->user(), Warehouse::query()->findOrFail($data['warehouse_id']));

        try {
            $dispatch = $this->dispatches->create($data, $data['lines'], $request->user()->id);
        } catch (DispatchException|RuntimeException $e) {
            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }

        return redirect()->route('dispatches.show', $dispatch)
            ->withToast('success', "{$dispatch->number} written up. Record the invoice, then let it go.");
    }

    public function show(Request $request, Dispatch $dispatch): Response
    {
        $this->authorize('view', $dispatch);

        $dispatch->load([
            'facility:id,code,name,legal_name,gstin,address_line_1,address_line_2,city,state,pincode',
            'warehouse:id,code,name', 'customer', 'customer.client:id,code,name',
            'lines.item:id,code,name,stock_uom_id,client_id', 'lines.item.stockUom:id,code', 'lines.lot:id,batch_number,expiry_at,owner_client_id', 'lines.lot.ownerClient:id,name',
            'lines.uom:id,code', 'attachments.uploader:id,name',
            'creator:id,name', 'invoicer:id,name', 'dispatcher:id,name', 'deliverer:id,name',
        ]);

        $user = $request->user();
        $readiness = $this->dispatches->readiness($dispatch);
        $editable = $dispatch->status->isEditable();
        $atFacility = $this->access->canWorkAt($user, $dispatch->facility_id);
        $seller = $this->einvoice->seller($dispatch->facility);

        return Inertia::render('dispatch/show', [
            'dispatch' => [
                'id' => $dispatch->id,
                'number' => $dispatch->number,
                'status' => $dispatch->status->value,
                'status_label' => $dispatch->status->label(),
                'status_tone' => $dispatch->status->tone(),
                'reference' => $dispatch->reference,
                'notes' => $dispatch->notes,
                'facility' => ['id' => $dispatch->facility_id, 'name' => $dispatch->facility->name],
                'store' => ['id' => $dispatch->warehouse_id, 'code' => $dispatch->warehouse->code, 'name' => $dispatch->warehouse->name],
                'seller' => $seller,
                'customer' => [
                    'id' => $dispatch->customer->id,
                    'code' => $dispatch->customer->code,
                    'name' => $dispatch->customer->name,
                    'legal_name' => $dispatch->customer->legal_name,
                    'gstin' => $dispatch->customer->gstin,
                    'kind_label' => $dispatch->customer->kind->label(),
                    'client' => $dispatch->customer->client?->name,
                    'address' => implode(', ', array_filter([
                        $dispatch->customer->billing_address_line_1, $dispatch->customer->billing_address_line_2,
                        $dispatch->customer->billing_city, $dispatch->customer->billing_state, $dispatch->customer->billing_pincode,
                    ])),
                ],
                'ship_to' => $dispatch->ship_to_name === null ? null : [
                    'name' => $dispatch->ship_to_name,
                    'gstin' => $dispatch->ship_to_gstin,
                    'address' => implode(', ', array_filter([
                        $dispatch->ship_to_address_line_1, $dispatch->ship_to_address_line_2,
                        $dispatch->ship_to_city, $dispatch->ship_to_state, $dispatch->ship_to_pincode,
                    ])),
                ],
                'place_of_supply' => $dispatch->place_of_supply,
                'place_of_supply_name' => GstStateCodes::name($dispatch->place_of_supply),
                'is_interstate' => $dispatch->is_interstate,
                'invoice_number' => $dispatch->invoice_number,
                'invoice_date' => $dispatch->invoice_date?->toDateString(),
                'irn' => $dispatch->irn,
                'ack_number' => $dispatch->ack_number,
                'ack_date' => $dispatch->ack_date?->toIso8601String(),
                'has_signed_qr' => $dispatch->signed_qr !== null,
                'transport' => [
                    'transporter_name' => $dispatch->transporter_name,
                    'transporter_gstin' => $dispatch->transporter_gstin,
                    'vehicle_number' => $dispatch->vehicle_number,
                    'lr_number' => $dispatch->lr_number,
                    'lr_date' => $dispatch->lr_date?->toDateString(),
                    'eway_bill_number' => $dispatch->eway_bill_number,
                    'eway_bill_date' => $dispatch->eway_bill_date?->toDateString(),
                    'distance_km' => $dispatch->distance_km,
                ],
                'money' => [
                    'taxable_value' => $dispatch->taxable_value,
                    'cgst' => $dispatch->cgst,
                    'sgst' => $dispatch->sgst,
                    'igst' => $dispatch->igst,
                    'other_charges' => $dispatch->other_charges,
                    'round_off' => $dispatch->round_off,
                    'total_value' => $dispatch->total_value,
                ],
                'delivery_note' => $dispatch->delivery_note,
                'cancel_reason' => $dispatch->cancel_reason,
                'timeline' => [
                    ['label' => 'Written up', 'by' => $dispatch->creator?->name, 'at' => $dispatch->created_at?->toIso8601String(), 'done' => true],
                    ['label' => 'Invoiced', 'by' => $dispatch->invoicer?->name, 'at' => $dispatch->invoiced_at?->toIso8601String(), 'done' => $dispatch->invoiced_at !== null],
                    ['label' => 'Dispatched', 'by' => $dispatch->dispatcher?->name, 'at' => $dispatch->dispatched_at?->toIso8601String(), 'done' => $dispatch->dispatched_at !== null],
                    ['label' => 'Delivered', 'by' => $dispatch->deliverer?->name, 'at' => $dispatch->delivered_at?->toIso8601String(), 'done' => $dispatch->delivered_at !== null],
                ],
                'cancelled_at' => $dispatch->cancelled_at?->toIso8601String(),
            ],
            'lines' => $dispatch->lines->map(fn (DispatchLine $l): array => [
                'id' => $l->id,
                'line_no' => $l->line_no,
                'item_id' => $l->item_id,
                'item_code' => $l->item?->code ?? '—',
                'item_name' => $l->item?->name ?? 'Product no longer on file',
                'batch' => $l->lot?->batch_number,
                'expiry_at' => $l->lot?->expiry_at?->toDateString(),
                'owner' => $l->lot?->ownerClient?->name ?? config('erp.company.brand'),
                'uom' => $l->uom?->code ?? $l->item?->stockUom?->code,
                'quantity' => $l->quantity,
                'unit_price' => $l->unit_price,
                'discount_percent' => $l->discount_percent,
                'hsn_code' => $l->hsn_code,
                'gst_rate' => $l->gst_rate,
                'taxable_value' => $l->taxable_value,
                'cgst' => $l->cgst,
                'sgst' => $l->sgst,
                'igst' => $l->igst,
                'line_total' => $l->line_total,
                'description' => $l->description,
            ])->all(),
            'attachments' => $dispatch->attachments->map(fn (DispatchAttachment $a): array => [
                'id' => $a->id,
                'kind' => $a->kind->value,
                'kind_label' => $a->kind->label(),
                'name' => $a->original_name,
                'size' => $a->size,
                'uploaded_at' => $a->created_at?->toIso8601String(),
                'uploaded_by' => $a->uploader?->name,
                'url' => route('dispatches.attachments.show', [$dispatch, $a]),
            ])->all(),
            'attachmentKinds' => DispatchAttachmentKind::options(),
            'einvoice' => [
                'mandatory' => (bool) config('erp.dispatch.einvoice_mandatory', true),
                'requires_irn' => $readiness['requires_irn'],
                'ready' => $readiness['ready'],
                'missing' => $readiness['missing'],
                'json_url' => route('dispatches.einvoice', $dispatch),
            ],
            'today' => now()->toDateString(),
            'can' => [
                'invoice' => $editable && $atFacility && $user->can('invoice', $dispatch),
                'attach' => $dispatch->status !== DispatchStatus::Cancelled && $atFacility && ($user->can('invoice', $dispatch) || $user->can('create', Dispatch::class)),
                'dispatch' => $dispatch->status === DispatchStatus::Invoiced && $atFacility && $user->can('dispatch', $dispatch),
                'deliver' => $dispatch->status === DispatchStatus::Dispatched && $user->can('deliver', $dispatch),
                'cancel' => $editable && $atFacility && $user->can('cancel', $dispatch),
                'challan' => ! in_array($dispatch->status, [DispatchStatus::Draft, DispatchStatus::Cancelled], strict: true),
                'einvoice_json' => $dispatch->invoice_number !== null && $dispatch->status !== DispatchStatus::Cancelled,
            ],
        ]);
    }

    /**
     * The batches of an item a finished goods store can send right now.
     */
    public function lots(Request $request): JsonResponse
    {
        $this->authorize('create', Dispatch::class);

        $item = Item::query()->findOrFail($request->integer('item'));
        $store = Warehouse::query()->findOrFail($request->integer('store'));

        return response()->json($this->dispatches->lotsFor($item, $store));
    }

    public function invoice(RecordDispatchInvoiceRequest $request, Dispatch $dispatch): RedirectResponse
    {
        $this->authorize('invoice', $dispatch);
        $this->access->assertCanWorkAt($request->user(), $dispatch->facility);

        try {
            $dispatch = $this->dispatches->recordInvoice($dispatch, $request->validated(), $request->user()->id);
        } catch (DispatchException|RuntimeException $e) {
            return back()->withInput()->withErrors(['invoice_number' => $e->getMessage()]);
        }

        $readiness = $this->dispatches->readiness($dispatch);

        return back()->withToast(
            $readiness['ready'] ? 'success' : 'info',
            $readiness['ready']
                ? "Invoice {$dispatch->invoice_number} recorded on {$dispatch->number}. It is ready to go."
                : "Invoice {$dispatch->invoice_number} recorded on {$dispatch->number}. Still needed before it leaves: ".implode('; ', $readiness['missing']).'.',
        );
    }

    public function attach(AttachDispatchDocumentRequest $request, Dispatch $dispatch): RedirectResponse
    {
        $this->authorize('invoice', $dispatch);
        $this->access->assertCanWorkAt($request->user(), $dispatch->facility);

        try {
            $attachment = $this->dispatches->attach(
                $dispatch,
                $request->file('document'),
                DispatchAttachmentKind::from((string) $request->validated('kind')),
                $request->user()->id,
            );
        } catch (DispatchException|RuntimeException $e) {
            return back()->withErrors(['document' => $e->getMessage()]);
        }

        return back()->withToast('success', "{$attachment->kind->label()} attached to {$dispatch->number}.");
    }

    public function attachment(Dispatch $dispatch, DispatchAttachment $attachment): HttpResponse
    {
        $this->authorize('view', $dispatch);

        if ($attachment->dispatch_id !== $dispatch->id || ! Storage::disk(DispatchService::DISK)->exists($attachment->path)) {
            abort(404, 'That document is not attached to this dispatch.');
        }

        return Storage::disk(DispatchService::DISK)->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime ?? 'application/octet-stream',
        ]);
    }

    /**
     * The e-invoice as JSON in the IRP's own schema, for the bulk upload
     * tool or a GSP.
     */
    public function einvoice(Dispatch $dispatch): HttpResponse
    {
        $this->authorize('view', $dispatch);

        if ($dispatch->invoice_number === null) {
            abort(422, 'Record the invoice number and date first; the e-invoice is built from them.');
        }

        $payload = $this->einvoice->payload($dispatch);

        return response()->json([$payload], 200, [
            'Content-Disposition' => 'attachment; filename="einvoice-'.$dispatch->number.'.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    public function challan(Dispatch $dispatch): HttpResponse
    {
        $this->authorize('view', $dispatch);

        try {
            return $this->challans->render($dispatch)->stream($this->challans->filename($dispatch));
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function dispatch(DispatchGoodsRequest $request, Dispatch $dispatch): RedirectResponse
    {
        $this->authorize('dispatch', $dispatch);
        $this->access->assertCanWorkAt($request->user(), $dispatch->facility);

        try {
            $dispatch = $this->dispatches->dispatchGoods($dispatch, $request->validated(), $request->user()->id);
        } catch (DispatchException|RuntimeException $e) {
            return back()->withInput()->withErrors(['dispatch' => $e->getMessage()]);
        }

        return back()->withToast('success', "{$dispatch->number} has left {$dispatch->warehouse->name}. Stock is posted out against invoice {$dispatch->invoice_number}.");
    }

    public function deliver(Request $request, Dispatch $dispatch): RedirectResponse
    {
        $this->authorize('deliver', $dispatch);

        $note = $request->validate(['note' => ['nullable', 'string', 'max:255']])['note'] ?? null;

        try {
            $dispatch = $this->dispatches->deliver($dispatch, $request->user()->id, $note);
        } catch (DispatchException|RuntimeException $e) {
            return back()->withErrors(['dispatch' => $e->getMessage()]);
        }

        return back()->withToast('success', "{$dispatch->number} marked delivered.");
    }

    public function cancel(Request $request, Dispatch $dispatch): RedirectResponse
    {
        $this->authorize('cancel', $dispatch);
        $this->access->assertCanWorkAt($request->user(), $dispatch->facility);

        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null;

        try {
            $dispatch = $this->dispatches->cancel($dispatch, $reason);
        } catch (DispatchException|RuntimeException $e) {
            return back()->withErrors(['dispatch' => $e->getMessage()]);
        }

        return back()->withToast('success', "{$dispatch->number} cancelled. Nothing left the store.");
    }

    private function periodStart(?string $period): ?string
    {
        return match ($period) {
            'today' => now()->startOfDay()->toDateTimeString(),
            'week' => now()->subDays(7)->startOfDay()->toDateTimeString(),
            'month' => now()->startOfMonth()->toDateTimeString(),
            'quarter' => now()->subDays(90)->startOfDay()->toDateTimeString(),
            'year' => now()->startOfYear()->toDateTimeString(),
            default => null,
        };
    }
}
