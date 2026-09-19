<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manufacturing;

use App\Domain\Approvals\Exceptions\ApprovalException;
use App\Domain\Approvals\Models\Approval;
use App\Domain\Approvals\Services\ApprovalService;
use App\Domain\Approvals\Services\RiskAssessor;
use App\Domain\Contract\Enums\ArtworkStatus;
use App\Domain\Contract\Enums\ManufacturingType;
use App\Domain\Contract\Models\Client;
use App\Domain\Contract\Models\ClientArtwork;
use App\Domain\Contract\Models\ClientQcSpec;
use App\Domain\Contract\Services\ArtworkService;
use App\Domain\Contract\Services\ClientMaterialReconciliationService;
use App\Domain\Contract\Services\JobCostingService;
use App\Domain\Documents\Services\DocumentService;
use App\Domain\Intelligence\Services\ProductionAnalyticsService;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Manufacturing\Enums\ManufacturingOrderStatus;
use App\Domain\Manufacturing\Enums\ProductionStage;
use App\Domain\Manufacturing\Exceptions\ManufacturingException;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Manufacturing\Models\ManufacturingOrderAdjustment;
use App\Domain\Manufacturing\Models\ManufacturingOrderLine;
use App\Domain\Manufacturing\Services\BatchAdjustmentService;
use App\Domain\Manufacturing\Services\IssueVerificationService;
use App\Domain\Manufacturing\Services\ManufacturingOrderService;
use App\Domain\Manufacturing\Services\ProductionStageService;
use App\Domain\MasterData\Models\Item;
use App\Domain\Planning\Models\ProductionPlan;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manufacturing\CompleteManufacturingOrderRequest;
use App\Http\Requests\Manufacturing\UpdateOrderTermsRequest;
use App\Support\Scanning\Qr;
use App\Support\Scanning\ScanCode;
use App\Support\Tables\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

class ManufacturingOrderController extends Controller
{
    private const array SORTABLE = ['number', 'status', 'planned_quantity', 'started_at', 'completed_at', 'created_at'];

    public function __construct(
        private readonly ManufacturingOrderService $orders,
        private readonly FacilityAccess $access,
        private readonly JobCostingService $costing,
        private readonly ClientMaterialReconciliationService $reconciliation,
        private readonly ProductionStageService $stages,
        private readonly BatchAdjustmentService $adjustments,
        private readonly ProductionAnalyticsService $analytics,
        private readonly RiskAssessor $risk,
        private readonly ApprovalService $approvals,
        private readonly IssueVerificationService $verification,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ManufacturingOrder::class);

        $table = TableQuery::fromRequest($request, allowedFilters: ['status', 'type', 'client']);

        $query = ManufacturingOrder::query()
            ->with(['formula:id,code,name', 'product:id,code,name', 'plannedUom:id,code', 'plan:id,number', 'outputLot:id,batch_number,qc_status', 'client:id,code,name'])
            ->search($table->search);

        $status = $table->filter('status') ?? 'open';

        if ($status === 'open') {
            $query->open();
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($type = $table->filter('type')) {
            $query->where('manufacturing_type', $type);
        }

        if ($client = $table->filter('client')) {
            $query->where('client_id', (int) $client);
        }

        return Inertia::render('manufacturing/index', [
            'orders' => $table->paginate($table->applySorting($query, self::SORTABLE, fallback: 'created_at')),
            'table' => $table->toArray() + ['filters' => array_filter(['status' => $status, 'type' => $type ?: null, 'client' => $client ?: null])],
            'types' => ManufacturingType::options(),
            'clients' => Client::query()->active()->orderBy('name')->get(['id', 'code', 'name'])
                ->map(static fn (Client $c): array => ['value' => $c->id, 'label' => "{$c->name} ({$c->code})"])->all(),
            'statuses' => [
                ['value' => 'open', 'label' => 'Open'],
                ...array_map(static fn (ManufacturingOrderStatus $s): array => ['value' => $s->value, 'label' => $s->label()], ManufacturingOrderStatus::cases()),
                ['value' => 'all', 'label' => 'All'],
            ],
        ]);
    }

    public function store(Request $request, ProductionPlan $plan): RedirectResponse
    {
        $this->authorize('create', ManufacturingOrder::class);
        $this->authorize('view', $plan);

        try {
            $order = $this->orders->createFromPlan($plan, $request->user()->id, $request->input('notes'));
        } catch (ManufacturingException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return redirect()->route('manufacturing.show', $order)
            ->withToast('success', "{$order->number} opened from {$plan->number}. Approve it to reserve the materials.");
    }

    public function show(Request $request, ManufacturingOrder $order): Response
    {
        $this->authorize('view', $order);

        $order->load([
            'formula:id,code,name',
            'formulaVersion:id,version_number',
            'product:id,code,name,stock_uom_id,requires_qc,shelf_life_days',
            'product.stockUom:id,code,dimension',
            'plannedUom:id,code',
            'plan:id,number,status',
            'client:id,code,name',
            'outputLot:id,batch_number,qc_status,expiry_at,initial_quantity',
            'createdBy:id,name',
            'approvedBy:id,name',
            'completedBy:id,name',
            'lines.item:id,code,name,type,standard_cost',
            'lines.uom:id,code',
        ]);

        $reservations = $order->reservations()
            ->with(['lot:id,batch_number,expiry_at', 'warehouse:id,code'])
            ->orderBy('id')
            ->get()
            ->map(static fn (StockReservation $r): array => [
                'id' => $r->id,
                'item_id' => $r->item_id,
                'lot' => $r->lot?->batch_number,
                'expiry_at' => $r->lot?->expiry_at?->toDateString(),
                'warehouse' => $r->warehouse?->code,
                'quantity' => $r->quantity,
                'consumed' => $r->consumed_quantity,
                'status' => $r->status->value,
            ]);

        return Inertia::render('manufacturing/show', [
            'order' => $order,
            'lines' => $order->lines->map(static fn (ManufacturingOrderLine $l): array => [
                'id' => $l->id,
                'line_no' => $l->line_no,
                'store_kind' => $l->store_kind->value,
                'item_id' => $l->item_id,
                'item_code' => $l->item->code,
                'item_name' => $l->item->name,
                'item_type' => $l->item->type->value,
                'uom' => $l->uom->code,
                'percentage' => $l->percentage,
                'is_qs' => $l->is_qs,
                'as_required' => $l->as_required,
                'planned' => $l->planned_quantity,
                'reserved' => $l->reserved_quantity,
                'consumed' => $l->consumed_quantity,
            ])->all(),
            'reservations' => $reservations->groupBy('item_id')->map->values()->all(),
            'today' => now()->toDateString(),
            // Everything a third-party job carries beyond an own-brand one.
            'thirdParty' => $this->thirdParty($request, $order),
            // Where the batch is on the floor, and what it took and cost.
            'stages' => $this->stages->summary($order),
            'approval' => $this->pendingApproval($order),
            'verification' => in_array($order->status, [ManufacturingOrderStatus::Approved, ManufacturingOrderStatus::InProgress], true) ? $this->verification->status($order) : null,
            'scanCode' => ScanCode::order($order->number),
            'documents' => app(DocumentService::class)->currentFor($order->product_id, $order->client_id),
            // How the pack must look: the approved artwork for this product.
            'artworks' => app(ArtworkService::class)->forBatch($order),
            'analytics' => $order->status === ManufacturingOrderStatus::Draft || $order->status === ManufacturingOrderStatus::Approved
                ? null
                : [
                    'consumption' => $this->analytics->consumptionForOrder($order),
                    'cost' => $request->user()->can('costing.view') || $request->user()->can('production.approve') ? $this->analytics->costVariance($order) : null,
                ],
            'lots' => $order->status === ManufacturingOrderStatus::InProgress || $order->status === ManufacturingOrderStatus::Completed
                ? $order->reservations()->with('lot:id,batch_number,item_id')->get()->map(fn (StockReservation $r) => ['item_id' => $r->item_id, 'lot_id' => $r->lot_id, 'batch_number' => $r->lot?->batch_number])->filter(fn ($l) => $l['lot_id'] !== null)->unique('lot_id')->values()->all()
                : [],
            'can' => [
                'terms' => $order->isThirdParty() && ($request->user()->can('production.edit') || $request->user()->can('costing.edit')),
                'approve' => $request->user()->can('approve', $order) && $order->status === ManufacturingOrderStatus::Draft,
                'start' => $request->user()->can('start', $order) && $order->status === ManufacturingOrderStatus::Approved,
                'complete' => $request->user()->can('complete', $order) && $order->status === ManufacturingOrderStatus::InProgress,
                'cancel' => $request->user()->can('cancel', $order) && $order->status->isOpen(),
                'stage' => $request->user()->can('production.consume') && $order->status === ManufacturingOrderStatus::InProgress,
                'adjust' => $request->user()->can('production.consume') && in_array($order->status, [ManufacturingOrderStatus::InProgress, ManufacturingOrderStatus::Completed], true),
            ],
        ]);
    }

    /**
     * Scan before issue: a batch code checked against this order.
     */
    public function scan(Request $request, ManufacturingOrder $order): RedirectResponse|JsonResponse
    {
        $this->authorize('start', $order);

        $data = $request->validate(['code' => ['required', 'string', 'max:255']]);
        $result = $this->verification->scan($order, $data['code'], $request->user()->id);

        if ($request->wantsJson()) {
            return response()->json($result);
        }

        return back()->withToast(
            $result['verdict'] === 'ok' ? 'success' : 'error',
            $result['verdict'] === 'ok'
                ? (($result['lot']['batch_number'] ?? $data['code']).' verified for '.$order->number.'.')
                : 'Blocked: '.implode(' ', $result['reasons']),
        );
    }

    /**
     * A printable batch card with the order's QR for the floor.
     */
    public function card(Request $request, ManufacturingOrder $order): Response
    {
        $this->authorize('view', $order);
        $order->load(['product:id,code,name', 'formula:id,code,name', 'formulaVersion:id,version_number', 'plannedUom:id,code', 'lines.item:id,code,name', 'lines.uom:id,code', 'client:id,name', 'facility:id,name']);

        return Inertia::render('manufacturing/card', [
            'order' => $order,
            'qr' => Qr::dataUri(ScanCode::url(ScanCode::order($order->number)), 260),
            'code' => ScanCode::order($order->number),
            'company' => config('erp.company.name'),
        ]);
    }

    /**
     * The floor says where the batch is: "mixing, 60%".
     */
    public function stage(Request $request, ManufacturingOrder $order): RedirectResponse
    {
        $this->authorize('start', $order);
        $this->assertAtFacility($request, $order);

        $data = $request->validate([
            'stage' => ['required', Rule::enum(ProductionStage::class)],
            'progress' => ['required', 'integer', 'min:0', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->stages->record($order, ProductionStage::from($data['stage']), (int) $data['progress'], $data['note'] ?? null, $request->user()->id);
        } catch (ManufacturingException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return back()->withToast('success', "{$order->number}: ".ProductionStage::from($data['stage'])->label()." {$data['progress']}%.");
    }

    /**
     * Material the batch gave back or lost after it was issued.
     */
    public function adjust(Request $request, ManufacturingOrder $order): RedirectResponse
    {
        $this->authorize('start', $order);
        $this->assertAtFacility($request, $order);

        $data = $request->validate([
            'kind' => ['required', Rule::in([ManufacturingOrderAdjustment::RETURN, ManufacturingOrderAdjustment::WASTAGE])],
            'item_id' => ['required', 'integer', Rule::exists('items', 'id')],
            'lot_id' => ['nullable', 'integer', Rule::exists('inventory_lots', 'id')],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->adjustments->record(
                $order,
                $data['kind'],
                Item::query()->findOrFail($data['item_id']),
                isset($data['lot_id']) && $data['lot_id'] ? InventoryLot::query()->findOrFail($data['lot_id']) : null,
                (string) $data['quantity'],
                $data['reason'] ?? null,
                $request->user()->id,
            );
        } catch (ManufacturingException|InvalidArgumentException $e) {
            return back()->withErrors(['quantity' => $e->getMessage()]);
        }

        return back()->withToast('success', $data['kind'] === ManufacturingOrderAdjustment::RETURN ? 'Return booked back into the store.' : 'Wastage recorded on the batch.');
    }

    public function approve(Request $request, ManufacturingOrder $order): RedirectResponse
    {
        $this->authorize('approve', $order);
        $this->assertAtFacility($request, $order);

        // Approval by risk, not just amount: a trigger, or the order's own
        // author releasing it, sends the release to a second signature.
        $triggers = $this->risk->forManufacturingRelease($order);

        if ((int) $order->created_by === (int) $request->user()->id && ! $request->user()->isSuperAdmin()) {
            $triggers[] = ['key' => 'maker_checker', 'reason' => 'The person who raised the order cannot release it.'];
        }

        if ($triggers !== []) {
            try {
                $this->approvals->request($order, 'production.release', $request->user(), ['triggers' => $triggers], $request->input('note'));
            } catch (ApprovalException $e) {
                return back()->withToast('error', $e->getMessage());
            }

            return back()->withToast('info', "{$order->number} sent for approval: ".$triggers[0]['reason']);
        }

        try {
            $this->orders->approve($order, $request->user()->id);
        } catch (ManufacturingException|RuntimeException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return back()->withToast('success', "{$order->number} approved: every material is now held for it in its store.");
    }

    public function start(Request $request, ManufacturingOrder $order): RedirectResponse
    {
        $this->authorize('start', $order);
        $this->assertAtFacility($request, $order);

        try {
            $this->orders->start($order, $request->user()->id);
        } catch (ManufacturingException|RuntimeException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return back()->withToast('success', "{$order->number} started: raw materials issued from the store.");
    }

    public function complete(CompleteManufacturingOrderRequest $request, ManufacturingOrder $order): RedirectResponse
    {
        $this->authorize('complete', $order);
        $this->assertAtFacility($request, $order);

        try {
            $order = $this->orders->complete($order, $request->user()->id, $request->validated());
        } catch (ManufacturingException|RuntimeException $e) {
            // A fault in the packing account lands on the packing account.
            $field = str_contains($e->getMessage(), 'units') ? 'filled_units' : 'output_quantity';

            return back()->withErrors([$field => $e->getMessage()]);
        }

        $lot = $order->outputLot;

        return back()->withToast('success', $lot === null
            ? "{$order->number} completed."
            : "{$order->number} completed. Batch {$lot->batch_number} posted".($lot->qc_status->value === 'pending' ? ' to quarantine for QC.' : ' to the finished goods store.'));
    }

    /**
     * The commercial terms of a third-party job: manufacturing charge,
     * whether material is billed and at what markup, the other charges.
     */
    public function terms(UpdateOrderTermsRequest $request, ManufacturingOrder $order): RedirectResponse
    {
        $this->authorize('view', $order);

        abort_unless($request->user()->can('production.edit') || $request->user()->can('costing.edit'), 403);

        if (! $order->isThirdParty()) {
            return back()->withToast('error', "{$order->number} is an own-brand batch; there is no client to charge.");
        }

        $order->fill(['charges' => $request->validated()])->save();

        return back()->withToast('success', "Commercial terms saved on {$order->number}.");
    }

    public function cancel(Request $request, ManufacturingOrder $order): RedirectResponse
    {
        $this->authorize('cancel', $order);

        try {
            $this->orders->cancel($order, $request->user()->id, $request->input('reason'));
        } catch (ManufacturingException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return back()->withToast('success', "{$order->number} cancelled; held materials released.");
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pendingApproval(ManufacturingOrder $order): ?array
    {
        $approval = Approval::query()
            ->where('approvable_type', $order->getMorphClass())
            ->where('approvable_id', $order->id)
            ->where('workflow_key', 'production.release')
            ->open()
            ->with('requestedBy:id,name')
            ->first();

        return $approval === null ? null : [
            'id' => $approval->id,
            'requested_by' => $approval->requestedBy?->name,
            'requested_at' => $approval->requested_at?->toIso8601String(),
            'triggers' => $approval->context['triggers'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function thirdParty(Request $request, ManufacturingOrder $order): ?array
    {
        if (! $order->isThirdParty()) {
            return null;
        }

        $artworks = ClientArtwork::query()
            ->where('client_id', $order->client_id)
            ->where(fn ($q) => $q->where('product_id', $order->product_id)->orWhereNull('product_id'))
            ->orderByDesc('id')
            ->get();

        return [
            'client' => ['id' => $order->client->id, 'code' => $order->client->code, 'name' => $order->client->name],
            'client_po_ref' => $order->client_po_ref,
            'required_delivery_at' => $order->required_delivery_at?->toDateString(),
            'material_source' => $order->material_source?->value,
            'material_source_label' => $order->material_source?->label(),
            'client_supplied_item_ids' => $order->clientSuppliedItemIds(),
            'costing' => $request->user()->can('costing.view') || $request->user()->can('production.edit') ? $this->costing->forOrder($order) : null,
            'reconciliation' => $this->reconciliation->rows($order->client, $order),
            'artworks' => [
                'approved' => $artworks->where('status', ArtworkStatus::Approved)->map(fn (ClientArtwork $a) => ['id' => $a->id, 'kind' => $a->kind, 'title' => $a->title, 'version' => $a->version, 'approved_at' => $a->approved_at?->toDateString()])->values()->all(),
                'pending' => $artworks->where('status', ArtworkStatus::Pending)->count(),
            ],
            'qc_spec' => ClientQcSpec::query()->where('client_id', $order->client_id)->where('product_id', $order->product_id)->exists(),
        ];
    }

    /**
     * Holding the permission is not enough: the person must work at the
     * facility the batch is made at.
     */
    private function assertAtFacility(Request $request, ManufacturingOrder $order): void
    {
        if ($order->facility !== null) {
            $this->access->assertCanWorkAt($request->user(), $order->facility);
        }
    }
}
