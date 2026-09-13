<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Enums\StockCountStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Models\StockCountLine;
use App\Domain\Inventory\Services\StockCountService;
use App\Domain\MasterData\Models\Item;
use App\Domain\Warehousing\Models\Warehouse;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Http\Controllers\Controller;
use App\Support\Math\Decimal;
use App\Support\Scanning\ScanCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class StockCountController extends Controller
{
    public function __construct(
        private readonly StockCountService $counts,
        private readonly FacilityAccess $access,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', StockCount::class);
        $user = $request->user();

        $stores = Warehouse::query()->active()->where('is_quarantine', false)
            ->when(! $this->access->isCompanyWide($user), fn ($q) => $this->access->scopeStores($user, $q))
            ->orderBy('code')->get(['id', 'code', 'name', 'facility_id']);

        $counts = StockCount::query()
            ->with(['warehouse:id,code,name', 'startedBy:id,name', 'approvedBy:id,name'])
            ->withCount('lines')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (StockCount $c) => [
                'id' => $c->id,
                'number' => $c->number,
                'store' => $c->warehouse?->name,
                'store_code' => $c->warehouse?->code,
                'status' => $c->status->value,
                'status_label' => $c->status->label(),
                'lines' => $c->lines_count,
                'started_by' => $c->startedBy?->name,
                'started_at' => $c->started_at?->toDateString(),
                'approved_by' => $c->approvedBy?->name,
                'accuracy' => $c->status === StockCountStatus::Approved ? $this->counts->accuracy($c)['accuracy_percent'] : null,
            ])
            ->all();

        return Inertia::render('counts/index', [
            'counts' => $counts,
            'stores' => $stores->map(fn (Warehouse $w) => ['value' => $w->id, 'label' => "{$w->code} — {$w->name}"])->all(),
            'preselect' => $request->integer('warehouse') ?: null,
            'can' => ['create' => $user->can('create', StockCount::class)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', StockCount::class);

        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);
        $this->access->assertCanWorkIn($request->user(), $warehouse);

        try {
            $count = $this->counts->start($warehouse, $request->user()->id, $data['notes'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['warehouse_id' => $e->getMessage()]);
        }

        return redirect()->route('counts.show', $count)->withToast('success', "{$count->number} started with {$count->lines()->count()} lines from the system.");
    }

    public function show(Request $request, StockCount $count): Response
    {
        $this->authorize('view', $count);

        $count->load(['warehouse:id,code,name', 'lines.item:id,code,name,stock_uom_id', 'lines.item.stockUom:id,code', 'lines.lot:id,batch_number,expiry_at', 'lines.countedBy:id,name', 'startedBy:id,name', 'submittedBy:id,name', 'approvedBy:id,name']);
        $user = $request->user();
        $counters = $count->lines->pluck('counted_by')->push($count->started_by)->push($count->submitted_by)->filter()->unique();

        return Inertia::render('counts/show', [
            'count' => [
                'id' => $count->id,
                'number' => $count->number,
                'store' => $count->warehouse?->name,
                'store_id' => $count->warehouse_id,
                'status' => $count->status->value,
                'status_label' => $count->status->label(),
                'notes' => $count->notes,
                'started_by' => $count->startedBy?->name,
                'started_at' => $count->started_at?->toIso8601String(),
                'submitted_by' => $count->submittedBy?->name,
                'approved_by' => $count->approvedBy?->name,
                'approved_at' => $count->approved_at?->toIso8601String(),
            ],
            'lines' => $count->lines->map(fn (StockCountLine $l) => [
                'id' => $l->id,
                'item_id' => $l->item_id,
                'code' => $l->item?->code,
                'name' => $l->item?->name,
                'unit' => $l->item?->stockUom?->code,
                'lot_id' => $l->lot_id,
                'batch_number' => $l->lot?->batch_number,
                'scan_code' => $l->lot ? ScanCode::lot($l->lot->batch_number) : null,
                'system' => Decimal::strip($l->system_quantity),
                'counted' => $l->counted_quantity === null ? null : Decimal::strip($l->counted_quantity),
                'variance' => $l->variance() === null ? null : Decimal::strip($l->variance()),
                'note' => $l->note,
                'counted_by' => $l->countedBy?->name,
                'posted' => $l->inventory_transaction_id !== null,
            ])->values()->all(),
            'accuracy' => $this->counts->accuracy($count),
            'can' => [
                'count' => $count->status === StockCountStatus::Counting && $user->can('count', $count),
                'submit' => $count->status === StockCountStatus::Counting && $user->can('count', $count),
                'approve' => $count->status === StockCountStatus::Submitted && $user->can('approve', $count) && ! $counters->contains($user->id),
                'cancel' => $count->status !== StockCountStatus::Approved && $count->status !== StockCountStatus::Cancelled && $user->can('count', $count),
            ],
        ]);
    }

    /**
     * One line counted: by scan (a batch code) or by choosing the line.
     */
    public function line(Request $request, StockCount $count): RedirectResponse
    {
        $this->authorize('count', $count);

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:255'],
            'item_id' => ['nullable', 'integer', Rule::exists('items', 'id')],
            'lot_id' => ['nullable', 'integer', Rule::exists('inventory_lots', 'id')],
            'counted' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $lot = null;
        $item = null;

        if (! empty($data['code'])) {
            $parsed = ScanCode::parse($data['code']);
            $lot = InventoryLot::query()->where('batch_number', $parsed['value'])->first();

            if ($lot === null) {
                return back()->withErrors(['code' => "No batch matches \"{$parsed['value']}\"."]);
            }

            $item = $lot->item;
        } else {
            $lot = ! empty($data['lot_id']) ? InventoryLot::query()->find($data['lot_id']) : null;
            $item = ! empty($data['item_id']) ? Item::query()->find($data['item_id']) : $lot?->item;
        }

        if ($item === null) {
            return back()->withErrors(['code' => 'Scan a batch or choose a line.']);
        }

        try {
            $line = $this->counts->record($count, $item, $lot, (string) $data['counted'], $request->user()->id, $data['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['counted' => $e->getMessage()]);
        }

        $variance = $line->variance();

        return back()->withToast($variance !== null && ! $variance->isZero() ? 'warning' : 'success', ($lot?->batch_number ?? $item->name).': counted '.Decimal::strip($line->counted_quantity).($variance !== null && ! $variance->isZero() ? ' — differs from the system by '.Decimal::strip($variance).'.' : ' — matches the system.'));
    }

    public function submit(Request $request, StockCount $count): RedirectResponse
    {
        $this->authorize('count', $count);

        try {
            $this->counts->submit($count, $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return back()->withToast('success', "{$count->number} submitted; someone else must approve it before the adjustments post.");
    }

    public function approve(Request $request, StockCount $count): RedirectResponse
    {
        $this->authorize('approve', $count);

        try {
            $this->counts->approve($count, $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return back()->withToast('success', "{$count->number} approved; every difference is posted as an adjustment.");
    }

    public function cancel(Request $request, StockCount $count): RedirectResponse
    {
        $this->authorize('count', $count);

        try {
            $this->counts->cancel($count);
        } catch (InvalidArgumentException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return back()->withToast('success', "{$count->number} cancelled.");
    }
}
