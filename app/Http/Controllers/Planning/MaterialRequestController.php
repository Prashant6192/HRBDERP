<?php

declare(strict_types=1);

namespace App\Http\Controllers\Planning;

use App\Domain\Planning\Enums\MaterialRequestStatus;
use App\Domain\Planning\Enums\StoreKind;
use App\Domain\Planning\Exceptions\PlanningException;
use App\Domain\Planning\Models\MaterialRequest;
use App\Domain\Planning\Models\MaterialRequestLine;
use App\Domain\Planning\Services\MaterialRequestPdfService;
use App\Domain\Planning\Services\MaterialRequestService;
use App\Http\Controllers\Controller;
use App\Support\Tables\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Production Material Requests, as the store and purchase see them.
 */
class MaterialRequestController extends Controller
{
    private const array SORTABLE = ['number', 'status', 'requested_at', 'needed_by'];

    public function __construct(
        private readonly MaterialRequestService $requests,
        private readonly MaterialRequestPdfService $pdf,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', MaterialRequest::class);

        $table = TableQuery::fromRequest($request, allowedFilters: ['status', 'store']);

        $query = MaterialRequest::query()
            ->with(['plan:id,number,formula_id,product_id,planned_quantity,planned_uom_id', 'plan.formula:id,code,name', 'plan.product:id,name', 'plan.plannedUom:id,code', 'warehouse:id,code,name'])
            ->withCount(['lines', 'lines as short_lines_count' => fn ($q) => $q->where('quantity_to_order', '>', 0)])
            ->search($table->search);

        $status = $table->filter('status') ?? 'open';

        if ($status === 'open') {
            $query->open();
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($store = $table->filter('store')) {
            $query->where('store_kind', $store);
        }

        return Inertia::render('material-requests/index', [
            'requests' => $table->paginate($table->applySorting($query, self::SORTABLE, fallback: 'requested_at')),
            'table' => $table->toArray() + ['filters' => ['status' => $status, 'store' => $store ?? '']],
            'statuses' => [
                ['value' => 'open', 'label' => 'Open'],
                ...array_map(static fn (MaterialRequestStatus $s): array => ['value' => $s->value, 'label' => $s->label()], MaterialRequestStatus::cases()),
                ['value' => 'all', 'label' => 'All'],
            ],
            'stores' => array_map(static fn (StoreKind $k): array => ['value' => $k->value, 'label' => $k->label()], StoreKind::cases()),
        ]);
    }

    public function show(Request $request, MaterialRequest $materialRequest): Response
    {
        $this->authorize('view', $materialRequest);

        $materialRequest->load([
            'plan:id,number,formula_id,product_id,planned_quantity,planned_uom_id,planned_units,planned_start_date,status',
            'plan.formula:id,code,name',
            'plan.product:id,code,name',
            'plan.plannedUom:id,code',
            'warehouse:id,code,name',
            'requestedBy:id,name',
            'lines.item:id,code,name,type',
            'lines.uom:id,code',
            'goodsReceipts:id,number,material_request_id,status,received_at',
        ]);

        return Inertia::render('material-requests/show', [
            'request' => $materialRequest,
            'lines' => $materialRequest->lines->map(static fn (MaterialRequestLine $line): array => [
                'id' => $line->id,
                'line_no' => $line->line_no,
                'item_id' => $line->item_id,
                'item_code' => $line->item->code,
                'item_name' => $line->item->name,
                'uom' => $line->uom->code,
                'required' => $line->required_quantity,
                'available' => $line->available_quantity,
                'to_order' => $line->quantity_to_order,
                'restock' => $line->restock_quantity,
                'received' => $line->received_quantity,
                'outstanding' => $line->outstanding()->__toString(),
                'covered' => $line->isCovered(),
                'alert_level' => $line->alert_level->value,
            ])->all(),
            'can' => [
                'cancel' => $request->user()->can('cancel', $materialRequest) && $materialRequest->isOpen(),
                'receive' => $request->user()->can('purchase.receive') && $materialRequest->isOpen(),
                'print' => $request->user()->can('print', $materialRequest),
            ],
        ]);
    }

    public function pdf(MaterialRequest $materialRequest): HttpResponse
    {
        $this->authorize('print', $materialRequest);

        return $this->pdf->render($materialRequest)->stream($this->pdf->filename($materialRequest));
    }

    public function cancel(Request $request, MaterialRequest $materialRequest): RedirectResponse
    {
        $this->authorize('cancel', $materialRequest);

        try {
            $this->requests->cancel($materialRequest);
        } catch (PlanningException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return back()->withToast('success', "{$materialRequest->number} cancelled.");
    }
}
