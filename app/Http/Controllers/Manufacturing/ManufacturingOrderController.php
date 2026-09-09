<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manufacturing;

use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Manufacturing\Enums\ManufacturingOrderStatus;
use App\Domain\Manufacturing\Exceptions\ManufacturingException;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Manufacturing\Models\ManufacturingOrderLine;
use App\Domain\Manufacturing\Services\ManufacturingOrderService;
use App\Domain\Planning\Models\ProductionPlan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manufacturing\CompleteManufacturingOrderRequest;
use App\Support\Tables\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class ManufacturingOrderController extends Controller
{
    private const array SORTABLE = ['number', 'status', 'planned_quantity', 'started_at', 'completed_at', 'created_at'];

    public function __construct(private readonly ManufacturingOrderService $orders) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ManufacturingOrder::class);

        $table = TableQuery::fromRequest($request, allowedFilters: ['status']);

        $query = ManufacturingOrder::query()
            ->with(['formula:id,code,name', 'product:id,code,name', 'plannedUom:id,code', 'plan:id,number', 'outputLot:id,batch_number,qc_status'])
            ->search($table->search);

        $status = $table->filter('status') ?? 'open';

        if ($status === 'open') {
            $query->open();
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        return Inertia::render('manufacturing/index', [
            'orders' => $table->paginate($table->applySorting($query, self::SORTABLE, fallback: 'created_at')),
            'table' => $table->toArray() + ['filters' => ['status' => $status]],
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
            'outputLot:id,batch_number,qc_status,expiry_at,initial_quantity',
            'createdBy:id,name',
            'approvedBy:id,name',
            'completedBy:id,name',
            'lines.item:id,code,name,type',
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
            'can' => [
                'approve' => $request->user()->can('approve', $order) && $order->status === ManufacturingOrderStatus::Draft,
                'start' => $request->user()->can('start', $order) && $order->status === ManufacturingOrderStatus::Approved,
                'complete' => $request->user()->can('complete', $order) && $order->status === ManufacturingOrderStatus::InProgress,
                'cancel' => $request->user()->can('cancel', $order) && $order->status->isOpen(),
            ],
        ]);
    }

    public function approve(Request $request, ManufacturingOrder $order): RedirectResponse
    {
        $this->authorize('approve', $order);

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

        try {
            $order = $this->orders->complete($order, $request->user()->id, $request->validated());
        } catch (ManufacturingException|RuntimeException $e) {
            return back()->withErrors(['output_quantity' => $e->getMessage()]);
        }

        $lot = $order->outputLot;

        return back()->withToast('success', $lot === null
            ? "{$order->number} completed."
            : "{$order->number} completed. Batch {$lot->batch_number} posted".($lot->qc_status->value === 'pending' ? ' to quarantine for QC.' : ' to the finished goods store.'));
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
}
