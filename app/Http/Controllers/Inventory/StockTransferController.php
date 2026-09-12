<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Exceptions\StockTransferException;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\StockTransferLine;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\Inventory\Services\StockTransferService;
use App\Domain\MasterData\Models\Item;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\ReceiveStockTransferRequest;
use App\Http\Requests\Inventory\StoreStockTransferRequest;
use App\Support\Tables\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Stock transfers between facilities: raise, approve, dispatch, receive.
 */
class StockTransferController extends Controller
{
    private const array SORTABLE = ['number', 'status', 'expected_at', 'created_at', 'dispatched_at'];

    public function __construct(
        private readonly StockTransferService $transfers,
        private readonly StockBalanceService $balances,
        private readonly FacilityAccess $access,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', StockTransfer::class);

        $table = TableQuery::fromRequest($request, allowedFilters: ['status', 'facility', 'direction']);

        $query = StockTransfer::query()
            ->with(['sourceFacility:id,code,name', 'sourceStore:id,code,name', 'destinationFacility:id,code,name', 'destinationStore:id,code,name', 'requester:id,name'])
            ->withCount('lines');

        if ($table->search !== '') {
            $query->where(fn ($q) => $q->where('number', 'ilike', "%{$table->search}%")->orWhere('reason', 'ilike', "%{$table->search}%"));
        }

        if ($status = $table->filter('status')) {
            $status === 'open' ? $query->open() : $query->where('status', $status);
        }

        if ($facility = $table->filter('facility')) {
            $direction = $table->filter('direction');
            match ($direction) {
                'in' => $query->where('destination_facility_id', (int) $facility),
                'out' => $query->where('source_facility_id', (int) $facility),
                default => $query->forFacility((int) $facility),
            };
        }

        return Inertia::render('transfers/index', [
            'transfers' => $table->paginate($table->applySorting($query, self::SORTABLE, fallback: 'created_at')),
            'table' => $table->toArray(),
            'statuses' => $this->statusOptions(),
            'facilities' => Facility::query()->ordered()->get(['id', 'code', 'name'])->map(fn (Facility $f) => ['value' => $f->id, 'label' => $f->name])->all(),
            'can' => ['create' => $request->user()->can('create', StockTransfer::class)],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', StockTransfer::class);

        $stores = Warehouse::query()
            ->availableForIssue()
            ->whereNotNull('facility_id')
            ->with(['facility:id,code,name,is_active,can_store,can_receive', 'category:id,badge,name'])
            ->orderBy('facility_id')->orderBy('sort_order')->orderBy('code')
            ->get()
            ->filter(fn (Warehouse $w) => $w->facility?->is_active)
            ->values();

        $sources = $this->access->scopeStores($request->user(), Warehouse::query()->whereIn('id', $stores->pluck('id')))->pluck('id')->all();

        return Inertia::render('transfers/create', [
            'stores' => $stores->map(fn (Warehouse $w) => [
                'id' => $w->id,
                'code' => $w->code,
                'name' => $w->name,
                'badge' => $w->badge(),
                'type' => $w->type->value,
                'facility_id' => $w->facility_id,
                'facility' => $w->facility->name,
                'can_be_source' => in_array($w->id, $sources, strict: true),
                'can_receive' => $w->facility->can_store && $w->facility->can_receive,
            ])->all(),
            'items' => Item::query()->active()->with('stockUom:id,code')->orderBy('name')->get(['id', 'code', 'name', 'type', 'stock_uom_id'])
                ->map(fn (Item $i) => ['value' => $i->id, 'label' => "{$i->name} ({$i->code})", 'type' => $i->type->value, 'uom' => $i->stockUom?->code])->all(),
            'preset' => [
                'source_warehouse_id' => $request->integer('source') ?: null,
                'destination_warehouse_id' => $request->integer('destination') ?: null,
                'destination_facility_id' => $request->integer('to_facility') ?: null,
                'source_facility_id' => $request->integer('from_facility') ?: null,
                'item_id' => $request->integer('item') ?: null,
                'quantity' => $request->query('quantity'),
                'reason' => $request->query('reason'),
            ],
            'today' => now()->toDateString(),
        ]);
    }

    public function store(StoreStockTransferRequest $request): RedirectResponse
    {
        $this->authorize('create', StockTransfer::class);

        $data = $request->validated();
        $source = Warehouse::query()->findOrFail($data['source_warehouse_id']);
        $this->access->assertCanWorkIn($request->user(), $source);

        try {
            $transfer = $this->transfers->create($data, $data['lines'], $request->user()->id);

            if (($data['submit'] ?? 'request') === 'request') {
                $transfer = $this->transfers->request($transfer, $request->user()->id);
            }
        } catch (StockTransferException|RuntimeException $e) {
            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }

        return redirect()->route('transfers.show', $transfer)->withToast('success', "{$transfer->number} raised: {$transfer->status->label()}.");
    }

    public function show(Request $request, StockTransfer $transfer): Response
    {
        $this->authorize('view', $transfer);

        $transfer->load([
            'lines.item:id,code,name,type', 'lines.lot:id,batch_number,expiry_at,qc_status', 'lines.uom:id,code',
            'sourceFacility:id,code,name', 'sourceStore:id,code,name,type', 'destinationFacility:id,code,name', 'destinationStore:id,code,name,type',
            'requester:id,name', 'approver:id,name', 'dispatcher:id,name', 'receiver:id,name',
        ]);

        $user = $request->user();
        $status = $transfer->status;

        $lines = $transfer->lines->map(function (StockTransferLine $line) use ($transfer): array {
            $free = $line->lot_id === null
                ? $this->balances->availableForProduction($line->item, [$transfer->source_warehouse_id])
                : (StockBalance::query()->where('item_id', $line->item_id)->where('warehouse_id', $transfer->source_warehouse_id)->where('lot_id', $line->lot_id)->first()?->available() ?? '0');

            return [
                'id' => $line->id,
                'line_no' => $line->line_no,
                'item_id' => $line->item_id,
                'item_code' => $line->item->code,
                'item_name' => $line->item->name,
                'item_type' => $line->item->type->value,
                'batch' => $line->lot?->batch_number,
                'expiry_at' => $line->lot?->expiry_at?->toDateString(),
                'uom' => $line->uom->code,
                'requested' => $line->quantity_requested,
                'dispatched' => $line->quantity_dispatched,
                'received' => $line->quantity_received,
                'written_off' => $line->quantity_written_off,
                'outstanding' => $line->outstanding()->__toString(),
                'available_at_source' => (string) $free,
                'notes' => $line->discrepancy_notes,
            ];
        })->all();

        return Inertia::render('transfers/show', [
            'transfer' => [
                'id' => $transfer->id,
                'number' => $transfer->number,
                'status' => $status->value,
                'status_label' => $status->label(),
                'status_tone' => $status->tone(),
                'requires_inspection' => $transfer->requires_inspection,
                'expected_at' => $transfer->expected_at?->toDateString(),
                'reason' => $transfer->reason,
                'notes' => $transfer->notes,
                'vehicle_ref' => $transfer->vehicle_ref,
                'source' => ['facility_id' => $transfer->source_facility_id, 'facility' => $transfer->sourceFacility->name, 'store_id' => $transfer->source_warehouse_id, 'store' => $transfer->sourceStore->name, 'store_code' => $transfer->sourceStore->code],
                'destination' => ['facility_id' => $transfer->destination_facility_id, 'facility' => $transfer->destinationFacility->name, 'store_id' => $transfer->destination_warehouse_id, 'store' => $transfer->destinationStore->name, 'store_code' => $transfer->destinationStore->code],
                'timeline' => [
                    ['label' => 'Requested', 'by' => $transfer->requester?->name, 'at' => $transfer->requested_at?->toIso8601String(), 'done' => $transfer->requested_at !== null],
                    ['label' => 'Approved', 'by' => $transfer->approver?->name, 'at' => $transfer->approved_at?->toIso8601String(), 'done' => $transfer->approved_at !== null],
                    ['label' => 'Dispatched', 'by' => $transfer->dispatcher?->name, 'at' => $transfer->dispatched_at?->toIso8601String(), 'done' => $transfer->dispatched_at !== null],
                    ['label' => 'Received', 'by' => $transfer->receiver?->name, 'at' => $transfer->received_at?->toIso8601String(), 'done' => $transfer->received_at !== null && ! $status->isOpen()],
                ],
                'created_at' => $transfer->created_at?->toIso8601String(),
            ],
            'lines' => $lines,
            'can' => [
                'request' => $status === StockTransferStatus::Draft && $user->can('request', $transfer),
                'approve' => in_array($status, [StockTransferStatus::Requested, StockTransferStatus::Draft], strict: true) && $user->can('approve', $transfer) && $this->access->canWorkAt($user, $transfer->source_facility_id),
                'reject' => $status === StockTransferStatus::Requested && $user->can('approve', $transfer),
                'pack' => $status === StockTransferStatus::Approved && $user->can('dispatch', $transfer) && $this->access->canWorkAt($user, $transfer->source_facility_id),
                'dispatch' => in_array($status, [StockTransferStatus::Approved, StockTransferStatus::Packed], strict: true) && $user->can('dispatch', $transfer) && $this->access->canWorkAt($user, $transfer->source_facility_id),
                'transit' => $status === StockTransferStatus::Dispatched && $user->can('dispatch', $transfer),
                'receive' => $status->canBeReceived() && $user->can('receive', $transfer) && $this->access->canWorkAt($user, $transfer->destination_facility_id),
                'cancel' => $status->canBeCancelled() && $user->can('cancel', $transfer),
            ],
        ]);
    }

    public function request(Request $request, StockTransfer $transfer): RedirectResponse
    {
        $this->authorize('request', $transfer);

        return $this->act(fn () => $this->transfers->request($transfer, $request->user()->id), "{$transfer->number} sent for approval.");
    }

    public function approve(Request $request, StockTransfer $transfer): RedirectResponse
    {
        $this->authorize('approve', $transfer);
        $this->access->assertCanWorkAt($request->user(), $transfer->sourceFacility);

        return $this->act(fn () => $this->transfers->approve($transfer, $request->user()->id), "{$transfer->number} approved: the stock is held at the source.");
    }

    public function reject(Request $request, StockTransfer $transfer): RedirectResponse
    {
        $this->authorize('approve', $transfer);
        $reason = (string) $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? '';

        return $this->act(fn () => $this->transfers->reject($transfer, $request->user()->id, $reason), "{$transfer->number} rejected.");
    }

    public function pack(Request $request, StockTransfer $transfer): RedirectResponse
    {
        $this->authorize('dispatch', $transfer);
        $this->access->assertCanWorkAt($request->user(), $transfer->sourceFacility);

        return $this->act(fn () => $this->transfers->pack($transfer, $request->user()->id), "{$transfer->number} packed.");
    }

    public function dispatch(Request $request, StockTransfer $transfer): RedirectResponse
    {
        $this->authorize('dispatch', $transfer);
        $this->access->assertCanWorkAt($request->user(), $transfer->sourceFacility);
        $vehicle = $request->validate(['vehicle_ref' => ['nullable', 'string', 'max:64']])['vehicle_ref'] ?? null;

        return $this->act(fn () => $this->transfers->dispatch($transfer, $request->user()->id, $vehicle), "{$transfer->number} dispatched: the stock is in transit.");
    }

    public function transit(Request $request, StockTransfer $transfer): RedirectResponse
    {
        $this->authorize('dispatch', $transfer);

        return $this->act(fn () => $this->transfers->markInTransit($transfer), "{$transfer->number} marked in transit.");
    }

    public function receive(ReceiveStockTransferRequest $request, StockTransfer $transfer): RedirectResponse
    {
        $this->authorize('receive', $transfer);
        $this->access->assertCanWorkAt($request->user(), $transfer->destinationFacility);

        return $this->act(function () use ($request, $transfer): StockTransfer {
            return $this->transfers->receive($transfer, $request->user()->id, $request->receivedLines());
        }, null);
    }

    public function cancel(Request $request, StockTransfer $transfer): RedirectResponse
    {
        $this->authorize('cancel', $transfer);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;

        return $this->act(fn () => $this->transfers->cancel($transfer, $request->user()->id, $reason), "{$transfer->number} cancelled; anything held has been released.");
    }

    /**
     * Lots of an item that the source store can send, for the line picker.
     */
    public function lots(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StockTransfer::class);

        $item = Item::query()->findOrFail($request->integer('item'));
        $balances = $this->balances->releasableBalances($item, [$request->integer('store')]);

        return response()->json($balances->map(fn (StockBalance $b) => [
            'lot_id' => $b->lot_id,
            'batch' => $b->lot?->batch_number,
            'expiry_at' => $b->lot?->expiry_at?->toDateString(),
            'available' => (string) $b->available(),
        ])->values());
    }

    private function act(callable $action, ?string $message): RedirectResponse
    {
        try {
            $transfer = $action();
        } catch (StockTransferException|RuntimeException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        $message ??= match ($transfer->status) {
            StockTransferStatus::Received => "{$transfer->number} received in full.",
            StockTransferStatus::PartiallyReceived => "{$transfer->number} partly received; the rest is still in transit.",
            StockTransferStatus::Discrepancy => "{$transfer->number} received with a discrepancy recorded.",
            default => "{$transfer->number} updated.",
        };

        return back()->withToast('success', $message);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return [
            ['value' => 'open', 'label' => 'All open'],
            ...array_map(fn (StockTransferStatus $s) => ['value' => $s->value, 'label' => $s->label()], StockTransferStatus::cases()),
        ];
    }
}
