<?php

declare(strict_types=1);

namespace App\Http\Controllers\Quality;

use App\Domain\Approvals\Services\ApprovalService;
use App\Domain\Contract\Models\ClientQcSpec;
use App\Domain\Identity\Exceptions\PinException;
use App\Domain\Identity\Services\PersonalPinService;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Quality\Models\QcInspection;
use App\Domain\Quality\Services\LotStickerService;
use App\Domain\Quality\Services\QcInspectionService;
use App\Domain\Quality\Services\QcSlipService;
use App\Domain\Warehousing\Models\Warehouse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Quality\DecideQcInspectionRequest;
use App\Support\Tables\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class QcInspectionController extends Controller
{
    /**
     * @var list<string>
     */
    private const SORTABLE = ['number', 'status', 'created_at', 'decided_at'];

    public function __construct(
        private readonly QcInspectionService $inspections,
        private readonly LotStickerService $stickers,
        private readonly QcSlipService $slips,
        private readonly PersonalPinService $pins,
        private readonly ApprovalService $approvals,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', QcInspection::class);

        $table = TableQuery::fromRequest($request, allowedFilters: ['status']);

        $query = QcInspection::query()
            ->with(['lot:id,batch_number,expiry_at,received_at', 'item:id,code,name,stock_uom_id', 'item.stockUom:id,code', 'destinationWarehouse:id,code'])
            ->search($table->search);

        // The queue shows what is waiting unless asked otherwise.
        $status = $table->filter('status') ?? 'open';

        if ($status === 'open') {
            $query->open();
        } else {
            $query->where('status', $status);
        }

        return Inertia::render('qc/index', [
            'inspections' => $table->paginate($table->applySorting($query, self::SORTABLE, fallback: 'created_at')),
            'table' => ['filters' => ['status' => $status]] + $table->toArray(),
            'statuses' => [
                ['value' => 'open', 'label' => 'Awaiting decision'],
                ...array_map(fn (LotQcStatus $s) => ['value' => $s->value, 'label' => $s->label()],
                    [LotQcStatus::Approved, LotQcStatus::Rejected, LotQcStatus::OnHold]),
            ],
            'counts' => [
                'open' => QcInspection::query()->open()->count(),
                'approved_today' => QcInspection::query()->where('status', LotQcStatus::Approved->value)->whereDate('decided_at', today())->count(),
            ],
        ]);
    }

    public function show(Request $request, QcInspection $qcInspection): Response
    {
        $this->authorize('view', $qcInspection);

        $qcInspection->load([
            'lot.vendor:id,name', 'lot.qcDecidedBy:id,name', 'lot.ownerClient:id,code,name',
            'item:id,code,name,stock_uom_id,requires_qc,shelf_life_days', 'item.stockUom:id,code,display_scale',
            'receiptLine.receipt:id,number,received_at,invoice_ref',
            'destinationWarehouse:id,code,name', 'decidedBy:id,name',
        ]);

        $where = StockBalance::query()
            ->with('warehouse:id,code,name,is_quarantine')
            ->where('lot_id', $qcInspection->lot_id)
            ->where('on_hand', '>', 0)
            ->get()
            ->map(fn (StockBalance $b) => [
                'warehouse' => $b->warehouse?->code,
                'is_quarantine' => $b->warehouse?->is_quarantine,
                'on_hand' => $b->on_hand,
            ]);

        // A client's batch is checked against what that client asked for.
        $spec = $qcInspection->lot?->owner_client_id === null
            ? null
            : ClientQcSpec::query()->where('client_id', $qcInspection->lot->owner_client_id)->where('product_id', $qcInspection->item_id)->first();

        return Inertia::render('qc/show', [
            'inspection' => $qcInspection,
            'owner' => $qcInspection->lot?->ownerClient === null ? null : ['id' => $qcInspection->lot->ownerClient->id, 'code' => $qcInspection->lot->ownerClient->code, 'name' => $qcInspection->lot->ownerClient->name],
            'clientSpec' => $spec === null ? null : ['parameters' => $spec->parameters, 'notes' => $spec->notes],
            'stockLocations' => $where,
            'destinations' => Warehouse::query()->availableForIssue()->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn (Warehouse $w) => ['value' => $w->id, 'label' => "{$w->code} — {$w->name}"])->all(),
            'can' => [
                'approve' => $qcInspection->isOpen() && $request->user()->can('approve', $qcInspection),
                'override' => $qcInspection->status === LotQcStatus::Rejected && $request->user()->can('approve', $qcInspection),
                'reject' => $qcInspection->isOpen() && $request->user()->can('reject', $qcInspection),
                'hold' => $qcInspection->status === LotQcStatus::Pending && $request->user()->can('hold', $qcInspection),
                'sticker' => $qcInspection->status === LotQcStatus::Approved && $request->user()->can('printSticker', $qcInspection->lot),
                'slip' => ! $qcInspection->isOpen() && $request->user()->can('view', $qcInspection),
                'view_receipt' => $request->user()->can('purchase.view'),
            ],
            'pin' => [
                'required' => $this->pins->required('qc'),
                'set' => $request->user()->hasFormulaPin(),
            ],
        ]);
    }

    public function approve(DecideQcInspectionRequest $request, QcInspection $qcInspection): RedirectResponse
    {
        $this->authorize('approve', $qcInspection);

        if ($error = $this->signWithPin($request)) {
            return $error;
        }

        $destination = $request->filled('destination_warehouse_id')
            ? Warehouse::findOrFail($request->integer('destination_warehouse_id'))
            : null;

        // Releasing a lot QC already rejected is an override: it needs a
        // second signature, never one person's say-so.
        if ($qcInspection->status === LotQcStatus::Rejected) {
            $this->approvals->request($qcInspection, 'qc.override', $request->user(), [
                'triggers' => [['key' => 'qc_override', 'reason' => "{$qcInspection->number} was rejected; releasing it after all overrides that decision."]],
                'remarks' => $request->validated('remarks'),
                'parameters' => $request->validated('parameters'),
                'destination_warehouse_id' => $destination?->id,
            ], $request->validated('remarks'));

            Inertia::flash('toast', ['type' => 'info', 'message' => "{$qcInspection->number} was rejected earlier; your release has gone for a second signature."]);

            return to_route('qc.show', $qcInspection);
        }

        try {
            $this->inspections->approve(
                $qcInspection,
                $request->user()->id,
                $request->validated('remarks'),
                $request->validated('parameters'),
                $destination,
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$qcInspection->number} approved. Stock released to store; sticker ready to print."]);

        return to_route('qc.show', $qcInspection);
    }

    public function reject(DecideQcInspectionRequest $request, QcInspection $qcInspection): RedirectResponse
    {
        $this->authorize('reject', $qcInspection);

        if ($error = $this->signWithPin($request)) {
            return $error;
        }

        try {
            $this->inspections->reject(
                $qcInspection,
                $request->user()->id,
                (string) $request->validated('remarks'),
                $request->validated('parameters'),
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'warning', 'message' => "{$qcInspection->number} rejected. Stock stays in quarantine."]);

        return to_route('qc.show', $qcInspection);
    }

    public function hold(DecideQcInspectionRequest $request, QcInspection $qcInspection): RedirectResponse
    {
        $this->authorize('hold', $qcInspection);

        try {
            $this->inspections->hold($qcInspection, $request->user()->id, $request->validated('remarks'));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'info', 'message' => "{$qcInspection->number} placed on hold."]);

        return to_route('qc.show', $qcInspection);
    }

    /**
     * The QC slip for a decided inspection: PASSED or REJECTED, by whom,
     * with what was measured. Printed at the checkpoint.
     */
    public function slip(QcInspection $qcInspection): HttpResponse
    {
        $this->authorize('view', $qcInspection);

        try {
            $pdf = $this->slips->render($qcInspection);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return $pdf->stream($this->slips->filename($qcInspection));
    }

    /**
     * A decision is signed with the person's PIN, not merely clicked.
     */
    private function signWithPin(Request $request): ?RedirectResponse
    {
        if (! $this->pins->required('qc')) {
            return null;
        }

        try {
            $this->pins->verify($request->user(), $request->input('pin'), 'qc');
        } catch (PinException $e) {
            return back()->withErrors(['pin' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * The printable label for a released lot. Streams inline so the browser
     * opens its print dialogue rather than downloading a file.
     */
    public function sticker(InventoryLot $lot): HttpResponse
    {
        Gate::authorize('printSticker', $lot);

        try {
            $pdf = $this->stickers->render($lot);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return $pdf->stream($this->stickers->filename($lot));
    }
}
