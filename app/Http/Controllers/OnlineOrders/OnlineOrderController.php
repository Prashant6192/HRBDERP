<?php

declare(strict_types=1);

namespace App\Http\Controllers\OnlineOrders;

use App\Domain\Marketplace\Enums\LabelBatchStatus;
use App\Domain\Marketplace\Enums\ShipmentStatus;
use App\Domain\Marketplace\Enums\StockState;
use App\Domain\Marketplace\Exceptions\OnlineOrderException;
use App\Domain\Marketplace\Models\Brand;
use App\Domain\Marketplace\Models\HandoverSheet;
use App\Domain\Marketplace\Models\LabelBatch;
use App\Domain\Marketplace\Models\LabelFile;
use App\Domain\Marketplace\Models\LabelPrint;
use App\Domain\Marketplace\Models\Marketplace;
use App\Domain\Marketplace\Models\Shipment;
use App\Domain\Marketplace\Models\ShipmentLine;
use App\Domain\Marketplace\Services\BrandAccess;
use App\Domain\Marketplace\Services\OnlineOrderService;
use App\Domain\Marketplace\Support\Cutoff;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\LabelsReadyNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Dispatch → Online orders: the day's marketplace labels, from the
 * agency's upload to the courier's pickup.
 */
class OnlineOrderController extends Controller
{
    public function __construct(
        private readonly OnlineOrderService $orders,
        private readonly BrandAccess $brands,
        private readonly FacilityAccess $facilities,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('marketplace.view');
        $user = $request->user();

        $day = $this->day($request->string('date')->toString());
        $facilityId = $request->integer('facility') ?: null;

        $batches = $this->visibleBatches($user)
            ->whereDate('for_date', $day->toDateString())
            ->when($facilityId, fn (Builder $q) => $q->where('facility_id', $facilityId))
            ->with(['brand:id,name', 'marketplace:id,name', 'facility:id,name', 'warehouse:id,code,name', 'uploader:id,name', 'closer:id,name'])
            ->withCount([
                'shipments as total' => fn ($q) => $q->where('status', '<>', ShipmentStatus::Cancelled->value),
                'shipments as not_printed' => fn ($q) => $q->where('status', ShipmentStatus::Uploaded->value),
                'shipments as printed' => fn ($q) => $q->where('status', ShipmentStatus::Printed->value),
                'shipments as packed' => fn ($q) => $q->where('status', ShipmentStatus::Packed->value),
                'shipments as handed_over' => fn ($q) => $q->where('status', ShipmentStatus::HandedOver->value),
                'shipments as cancelled' => fn ($q) => $q->where('status', ShipmentStatus::Cancelled->value),
                'shipments as attention' => fn ($q) => $q->whereIn('status', ShipmentStatus::awaitingPacking())
                    ->where(fn ($q) => $q->whereIn('stock_state', [StockState::Unmapped->value, StockState::Short->value])->orWhereNull('awb')),
            ])
            ->orderBy('id')
            ->get();

        $batchIds = $batches->pluck('id');

        $couriers = Shipment::query()
            ->whereIn('label_batch_id', $batchIds)
            ->where('status', '<>', ShipmentStatus::Cancelled->value)
            ->selectRaw("COALESCE(courier, '') AS courier, status, COUNT(*) AS n")
            ->groupBy('courier', 'status')
            ->toBase()
            ->get()
            ->groupBy('courier')
            ->map(function ($rows, $courier): array {
                $by = $rows->pluck('n', 'status')->map(fn ($n) => (int) $n);

                return [
                    'courier' => $courier === '' ? null : $courier,
                    'total' => $by->sum(),
                    'not_printed' => $by->get(ShipmentStatus::Uploaded->value, 0),
                    'printed' => $by->get(ShipmentStatus::Printed->value, 0),
                    'packed' => $by->get(ShipmentStatus::Packed->value, 0),
                    'handed_over' => $by->get(ShipmentStatus::HandedOver->value, 0),
                ];
            })
            ->sortBy(fn (array $r) => $r['courier'] ?? '~')
            ->values()
            ->all();

        $totals = [
            'parcels' => (int) $batches->sum('total'),
            'not_printed' => (int) $batches->sum('not_printed'),
            'printed' => (int) $batches->sum('printed'),
            'packed' => (int) $batches->sum('packed'),
            'handed_over' => (int) $batches->sum('handed_over'),
            'cancelled' => (int) $batches->sum('cancelled'),
            'attention' => (int) $batches->sum('attention'),
        ];

        $search = trim($request->string('q')->toString());
        $found = $search === '' ? [] : $this->brands->scopeByBrand($user, Shipment::query())
            ->search($search)
            ->with(['lines.item:id,code,name', 'marketplace:id,name', 'brand:id,name', 'packer:id,name'])
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(fn (Shipment $s) => OnlineOrderPresenter::shipment($s, withStock: ! $this->brands->isRestricted($user)))
            ->all();

        $sheets = HandoverSheet::query()
            ->whereDate('handed_over_at', $day->toDateString())
            ->when(! $this->brands->isRestricted($user), fn ($q) => $q, fn ($q) => $q->whereRaw('1 = 0'))
            ->when($facilityId, fn (Builder $q) => $q->where('facility_id', $facilityId))
            ->with(['handedOverBy:id,name', 'warehouse:id,name'])
            ->latest('id')
            ->get()
            ->map(fn (HandoverSheet $h) => [
                'id' => $h->id, 'number' => $h->number, 'courier' => $h->courier, 'count' => $h->shipment_count,
                'store' => $h->warehouse?->name, 'by' => $h->handedOverBy?->name, 'received_by' => $h->received_by_name,
                'at' => $h->handed_over_at->toIso8601String(),
            ])
            ->all();

        $awaiting = $totals['not_printed'] + $totals['printed'];

        return Inertia::render('online-orders/index', [
            'date' => $day->toDateString(),
            'is_today' => $day->equalTo(Cutoff::today()),
            'cutoff' => Cutoff::on($day)->format('g:i A'),
            'past_cutoff' => Cutoff::passed($day) && $awaiting > 0,
            'batches' => $batches->map(fn (LabelBatch $b) => [
                ...OnlineOrderPresenter::batch($b),
                'counts' => [
                    'total' => (int) $b->total, 'not_printed' => (int) $b->not_printed, 'printed' => (int) $b->printed,
                    'packed' => (int) $b->packed, 'handed_over' => (int) $b->handed_over, 'cancelled' => (int) $b->cancelled,
                    'attention' => (int) $b->attention,
                ],
            ])->all(),
            'totals' => $totals,
            'couriers' => $couriers,
            'sheets' => $sheets,
            'search' => $search,
            'found' => $found,
            'facility' => $facilityId,
            'facilities' => $this->brands->isRestricted($user) ? [] : $this->facilities->scopeFacilities($user, Facility::query())
                ->active()->where('can_dispatch', true)->ordered()->get(['id', 'name'])
                ->map(fn (Facility $f) => ['value' => (string) $f->id, 'label' => $f->name])->all(),
            'can' => $this->abilities($user),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('marketplace.upload');
        $user = $request->user();

        $brands = $this->brands->scopeBrands($user, Brand::query())
            ->where('is_active', true)
            ->with('defaultWarehouse:id,code,name,facility_id', 'defaultWarehouse.facility:id,name')
            ->orderBy('name')
            ->get();

        $canChooseStore = $user->can('marketplace.manage');

        return Inertia::render('online-orders/upload', [
            'brands' => $brands->map(fn (Brand $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'store_id' => $b->default_warehouse_id,
                'store' => $b->defaultWarehouse ? "{$b->defaultWarehouse->facility?->name} · {$b->defaultWarehouse->name}" : null,
            ])->all(),
            'marketplaces' => Marketplace::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'reader'])
                ->map(fn (Marketplace $m) => ['id' => $m->id, 'name' => $m->name, 'reads' => $m->reader->label()])->all(),
            'stores' => $canChooseStore ? $this->dispatchStores($user) : [],
            'can_choose_store' => $canChooseStore,
            'today' => Cutoff::today()->toDateString(),
            'open' => $this->visibleBatches($user)
                ->where('status', LabelBatchStatus::Open->value)
                ->with(['brand:id,name', 'marketplace:id,name', 'warehouse:id,name'])
                ->withCount(['shipments' => fn ($q) => $q->where('status', '<>', ShipmentStatus::Cancelled->value)])
                ->latest('id')->limit(10)->get()
                ->map(fn (LabelBatch $b) => [...OnlineOrderPresenter::batch($b), 'parcels' => $b->shipments_count])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('marketplace.upload');
        $user = $request->user();

        $data = $request->validate([
            'brand_id' => ['required', 'integer', Rule::exists('brands', 'id')],
            'marketplace_id' => ['required', 'integer', Rule::exists('marketplaces', 'id')],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')],
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ], [
            'files.required' => 'Choose the label PDF to upload.',
            'files.*.mimes' => 'Upload the label PDF exactly as the marketplace gave it.',
            'files.*.max' => 'A label file can be at most 20 MB.',
        ]);

        $brand = Brand::query()->findOrFail($data['brand_id']);
        $this->brands->assertCanUse($user, $brand);
        $marketplace = Marketplace::query()->findOrFail($data['marketplace_id']);

        // Only the office chooses a store other than the brand's own.
        $storeId = $user->can('marketplace.manage') && ! empty($data['warehouse_id']) ? (int) $data['warehouse_id'] : $brand->default_warehouse_id;

        if ($storeId === null) {
            throw ValidationException::withMessages(['brand_id' => "The ERP does not know where {$brand->name}'s parcels ship from yet. Ask the office to set the brand's store."]);
        }

        $store = Warehouse::query()->with('facility')->findOrFail($storeId);

        try {
            $batch = $this->orders->upload($brand, $marketplace, $store, array_values($request->file('files')), $user);
        } catch (OnlineOrderException $e) {
            throw ValidationException::withMessages(['files' => $e->getMessage()]);
        }

        $parcels = $batch->shipments()->count();
        $attention = $batch->shipments()->where(fn ($q) => $q->whereIn('stock_state', [StockState::Unmapped->value, StockState::Short->value])->orWhereNull('awb'))->count();

        return to_route('online-orders.show', $batch)->withToast(
            $attention > 0 ? 'warning' : 'success',
            "{$batch->number}: {$parcels} parcel(s) so far".($attention > 0 ? ", {$attention} need attention." : ', all ready.'),
        );
    }

    public function show(Request $request, LabelBatch $batch): Response
    {
        $this->authorize('marketplace.view');
        $user = $request->user();
        $this->assertCanSee($user, $batch);
        $restricted = $this->brands->isRestricted($user);

        $batch->load(['brand:id,name,client_id', 'marketplace:id,name,reader', 'facility:id,name', 'warehouse:id,code,name', 'uploader:id,name', 'closer:id,name']);

        $shipments = $batch->shipments()
            ->with(['lines.item:id,code,name', 'packer:id,name'])
            ->orderByRaw("COALESCE(courier, '~')")
            ->orderBy('label_file_id')
            ->orderBy('id')
            ->get();

        $unmapped = ShipmentLine::query()
            ->join('shipments', 'shipments.id', '=', 'shipment_lines.shipment_id')
            ->where('shipments.label_batch_id', $batch->id)
            ->whereIn('shipments.status', ShipmentStatus::awaitingPacking())
            ->whereNull('shipment_lines.item_id')
            ->groupBy('shipment_lines.seller_sku')
            ->selectRaw('shipment_lines.seller_sku, COUNT(DISTINCT shipments.id) AS parcels, MAX(shipment_lines.description) AS description')
            ->orderBy('shipment_lines.seller_sku')
            ->get()
            ->map(fn ($r) => ['seller_sku' => $r->seller_sku, 'parcels' => (int) $r->parcels, 'description' => $r->description])
            ->all();

        return Inertia::render('online-orders/show', [
            'batch' => OnlineOrderPresenter::batch($batch),
            'files' => $batch->files()->with('uploader:id,name')->withCount('shipments')->get()
                ->map(fn (LabelFile $f) => OnlineOrderPresenter::file($f))->all(),
            'shipments' => $shipments->map(fn (Shipment $s) => OnlineOrderPresenter::shipment($s, withStock: true))->all(),
            'unmapped' => $unmapped,
            'shortfall' => $restricted ? [] : $this->orders->shortfall($batch),
            'prints' => $restricted ? [] : $batch->prints()->with('printer:id,name')->limit(20)->get()
                ->map(fn (LabelPrint $p) => ['scope' => $p->scope, 'courier' => $p->courier, 'shipments' => $p->shipment_count, 'pages' => $p->page_count, 'by' => $p->printer?->name, 'at' => $p->created_at?->toIso8601String()])->all(),
            'cutoff' => Cutoff::on($batch->for_date)->format('g:i A'),
            'past_cutoff' => Cutoff::passed($batch->for_date),
            'can' => [
                ...$this->abilities($user),
                'upload_here' => $user->can('marketplace.upload') && $batch->isOpen(),
                'close' => $user->can('marketplace.upload') && $batch->isOpen(),
                'correct' => $user->can('marketplace.upload') || $user->can('marketplace.manage'),
            ],
        ]);
    }

    public function close(Request $request, LabelBatch $batch): RedirectResponse
    {
        $this->authorize('marketplace.upload');
        $user = $request->user();
        $this->assertCanSee($user, $batch);

        try {
            $this->orders->close($batch, $user);
        } catch (OnlineOrderException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        $this->tellTheDepot($batch->refresh());

        return back()->withToast('success', "{$batch->number} closed. The depot has been told the labels are ready to print.");
    }

    public function holdAgain(Request $request, LabelBatch $batch): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('marketplace.print') || $user->can('marketplace.manage') || $user->can('marketplace.upload'), 403);
        $this->assertCanSee($user, $batch);

        $counts = $this->orders->holdAgain($batch);

        return back()->withToast(
            $counts['short'] + $counts['unmapped'] > 0 ? 'warning' : 'success',
            "Stock checked again: {$counts['held']} now held, {$counts['short']} still short, {$counts['unmapped']} not mapped.",
        );
    }

    /**
     * Record a print run and hand the browser the pages to assemble.
     */
    public function print(Request $request, LabelBatch $batch): JsonResponse
    {
        $this->authorize('marketplace.print');
        $user = $request->user();
        $this->assertCanSee($user, $batch);

        $data = $request->validate([
            'scope' => ['required', Rule::in(['all', 'unprinted', 'courier', 'one'])],
            'courier' => ['nullable', 'string', 'max:64'],
            'shipment_id' => ['nullable', 'integer', 'required_if:scope,one'],
        ]);

        try {
            $plan = $this->orders->print($batch, $data['scope'], $user, $data['courier'] ?? null, $data['shipment_id'] ?? null);
        } catch (OnlineOrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $files = collect($plan['parts'])->pluck('file_id')->unique()->values()
            ->mapWithKeys(fn (int $id) => [$id => route('online-orders.files.show', $id)])
            ->all();

        return response()->json([...$plan, 'files' => $files]);
    }

    /**
     * The label file as the marketplace gave it.
     */
    public function file(Request $request, LabelFile $file): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user->can('marketplace.print') || $user->can('marketplace.upload') || $user->can('marketplace.manage'), 403);
        $file->loadMissing('batch');
        $this->assertCanSee($user, $file->batch);

        abort_unless(Storage::disk(OnlineOrderService::DISK)->exists($file->path), 404);

        // The framework writes the filename into the header safely.
        return Storage::disk(OnlineOrderService::DISK)->response($file->path, $file->original_name, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    public function removeFile(Request $request, LabelFile $file): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('marketplace.upload') || $user->can('marketplace.manage'), 403);
        $file->loadMissing('batch');
        $this->assertCanSee($user, $file->batch);

        try {
            $this->orders->removeFile($file);
        } catch (OnlineOrderException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return back()->withToast('success', "{$file->original_name} removed, with its parcels.");
    }

    public function correct(Request $request, Shipment $shipment): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('marketplace.upload') || $user->can('marketplace.manage'), 403);
        $this->assertCanSee($user, $shipment->batch);

        $data = $request->validate([
            'awb' => ['nullable', 'string', 'max:64'],
            'courier' => ['nullable', 'string', 'max:64'],
            'order_number' => ['nullable', 'string', 'max:64'],
            'payment_mode' => ['nullable', Rule::in(['cod', 'prepaid', 'unknown'])],
            'lines' => ['nullable', 'array', 'min:1', 'max:20'],
            'lines.*.seller_sku' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        try {
            $this->orders->correct($shipment, $data);
        } catch (OnlineOrderException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return back()->withToast('success', 'Parcel updated.');
    }

    public function cancel(Request $request, Shipment $shipment): RedirectResponse
    {
        $user = $request->user();
        $this->assertCanSee($user, $shipment->batch);

        // The agency cancels what the marketplace cancelled, before it is
        // packed. Unpacking a packed parcel is the office's call.
        $allowed = $user->can('marketplace.manage')
            || ($user->can('marketplace.upload') && $shipment->status->awaitsPacking());
        abort_unless($allowed, 403);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $this->orders->cancel($shipment, $data['reason'], $user);
        } catch (OnlineOrderException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return back()->withToast('success', "{$shipment->reference()} cancelled.");
    }

    /**
     * Mark a parcel packed without its label being scanned — a damaged
     * label, a scanner down. Recorded with the reason.
     */
    public function packManually(Request $request, Shipment $shipment): RedirectResponse
    {
        $this->authorize('marketplace.manage');
        $user = $request->user();
        $this->assertCanSee($user, $shipment->batch);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $this->orders->pack($shipment, $user, 'manual', $data['reason']);
        } catch (OnlineOrderException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return back()->withToast('success', "{$shipment->reference()} marked packed.");
    }

    // ---- Internals ----------------------------------------------------------

    /**
     * @return Builder<LabelBatch>
     */
    private function visibleBatches(User $user): Builder
    {
        $query = $this->brands->scopeByBrand($user, LabelBatch::query());

        if (! $this->brands->isRestricted($user)) {
            $ids = $this->facilities->facilityIds($user);

            if ($ids !== null) {
                $query->whereIn('facility_id', $ids);
            }
        }

        return $query;
    }

    private function assertCanSee(User $user, LabelBatch $batch): void
    {
        abort_unless($this->visibleBatches($user)->whereKey($batch->id)->exists(), 403);
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(User $user): array
    {
        return [
            'upload' => $user->can('marketplace.upload'),
            'print' => $user->can('marketplace.print'),
            'pack' => $user->can('marketplace.pack'),
            'handover' => $user->can('marketplace.handover'),
            'manage' => $user->can('marketplace.manage'),
            'restricted' => $this->brands->isRestricted($user),
        ];
    }

    private function day(string $value): CarbonImmutable
    {
        try {
            return $value === '' ? Cutoff::today() : CarbonImmutable::parse($value, Cutoff::timezone())->startOfDay();
        } catch (Throwable) {
            return Cutoff::today();
        }
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function dispatchStores(User $user): array
    {
        return $this->facilities->scopeStores($user, Warehouse::query())
            ->where('type', WarehouseType::FinishedGoods->value)
            ->where('is_active', true)
            ->where('is_system', false)
            ->whereHas('facility', fn ($q) => $q->where('can_dispatch', true)->where('is_active', true))
            ->with('facility:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Warehouse $w) => ['value' => (string) $w->id, 'label' => "{$w->facility?->name} · {$w->name}"])
            ->all();
    }

    /**
     * Everyone who prints at the batch's facility hears the labels are in.
     */
    private function tellTheDepot(LabelBatch $batch): void
    {
        $batch->loadMissing(['brand:id,name', 'marketplace:id,name', 'facility:id,name']);

        $recipients = User::query()
            ->active()
            ->permission('marketplace.print')
            ->get()
            ->filter(fn (User $u) => $this->facilities->canWorkAt($u, $batch->facility_id) && ! $this->brands->isRestricted($u));

        if ($recipients->isEmpty()) {
            return;
        }

        try {
            Notification::send($recipients, new LabelsReadyNotification($batch, $batch->shipments()->where('status', '<>', ShipmentStatus::Cancelled->value)->count()));
        } catch (Throwable $e) {
            // The bell has been rung; a mail server that is down must not
            // undo the agency's "that's all".
            report($e);
        }
    }
}
