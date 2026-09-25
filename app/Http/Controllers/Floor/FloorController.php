<?php

declare(strict_types=1);

namespace App\Http\Controllers\Floor;

use App\Domain\Contract\Services\ArtworkService;
use App\Domain\Inventory\Enums\StockCountStatus;
use App\Domain\Inventory\Models\FloorPhoto;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Manufacturing\Enums\ManufacturingOrderStatus;
use App\Domain\Manufacturing\Enums\ProductionStage;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Manufacturing\Services\IssueVerificationService;
use App\Domain\Marketplace\Models\Shipment;
use App\Domain\MasterData\Models\Item;
use App\Domain\Planning\Enums\StoreKind;
use App\Domain\Warehousing\Models\Warehouse;
use App\Domain\Warehousing\Models\WarehouseLocation;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Http\Controllers\Controller;
use App\Support\Math\Decimal;
use App\Support\Scanning\ScanCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The mobile floor mode: a simplified interface for warehouse and factory
 * users — scan, receive, issue, approve, count, transfer, record
 * production, take a photo — without the full desktop ERP.
 */
class FloorController extends Controller
{
    public function __construct(
        private readonly IssueVerificationService $verification,
        private readonly FacilityAccess $access,
        private readonly ArtworkService $artworks,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $running = ManufacturingOrder::query()
            ->whereIn('status', [ManufacturingOrderStatus::Approved->value, ManufacturingOrderStatus::InProgress->value])
            ->when(! $this->access->isCompanyWide($user), fn ($q) => $q->whereIn('facility_id', $this->access->facilityIds($user) ?? []))
            ->with('product:id,name')
            ->orderByRaw("case status when 'in_progress' then 0 else 1 end")
            ->limit(8)
            ->get()
            ->map(fn (ManufacturingOrder $o) => ['id' => $o->id, 'number' => $o->number, 'product' => $o->product?->name, 'status' => $o->status->value, 'stage' => $o->current_stage?->label(), 'progress' => (int) $o->stage_progress])
            ->all();

        $counts = StockCount::query()
            ->where('status', StockCountStatus::Counting->value)
            ->with('warehouse:id,code,name')
            ->get()
            ->map(fn (StockCount $c) => ['id' => $c->id, 'number' => $c->number, 'store' => $c->warehouse?->name])
            ->all();

        return Inertia::render('floor/index', [
            'running' => $running,
            'counts' => $counts,
            'can' => [
                'receive' => $user->can('purchase.receive'),
                'issue' => $user->can('production.consume'),
                'qc' => $user->can('qc.view'),
                'count' => $user->can('inventory.count'),
                'transfer' => $user->can('inventory.transfer') || $user->can('inventory.receive_transfer'),
                'production' => $user->can('production.consume'),
                'photo' => $user->can('inventory.view'),
                'pack' => $user->can('marketplace.pack'),
                'handover' => $user->can('marketplace.handover'),
                'return' => $user->can('marketplace.return'),
            ],
        ]);
    }

    public function scan(Request $request): Response
    {
        $code = $request->string('c')->toString();

        return Inertia::render('floor/scan', [
            'code' => $code !== '' ? $code : null,
            'result' => $code !== '' ? $this->resolve($code, $request) : null,
        ]);
    }

    /**
     * What a code is: a batch, an order, a rack or a material, with the
     * actions the person may take on it.
     */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:255']]);

        return response()->json($this->resolve($data['code'], $request));
    }

    public function issue(Request $request, ManufacturingOrder $order): Response
    {
        $this->authorize('start', $order);
        $order->load('product:id,name');

        return Inertia::render('floor/issue', [
            'order' => ['id' => $order->id, 'number' => $order->number, 'product' => $order->product?->name, 'status' => $order->status->value],
            'verification' => $this->verification->status($order),
            'packaging' => $this->verification->status($order, StoreKind::Packaging),
        ]);
    }

    public function production(Request $request): Response
    {
        $user = $request->user();

        $orders = ManufacturingOrder::query()
            ->where('status', ManufacturingOrderStatus::InProgress->value)
            ->when(! $this->access->isCompanyWide($user), fn ($q) => $q->whereIn('facility_id', $this->access->facilityIds($user) ?? []))
            ->with('product:id,name')
            ->orderBy('started_at')
            ->get()
            ->map(fn (ManufacturingOrder $o) => [
                'id' => $o->id,
                'number' => $o->number,
                'product' => $o->product?->name,
                'stage' => $o->current_stage?->value,
                'stage_label' => $o->current_stage?->label(),
                'progress' => (int) $o->stage_progress,
                // The approved pack the line must match.
                'artworks' => array_values(array_filter($this->artworks->forBatch($o), fn (array $a) => $a['status'] === 'approved')),
            ])
            ->all();

        return Inertia::render('floor/production', [
            'orders' => $orders,
            'stages' => array_values(array_filter(ProductionStage::options(), fn (array $s) => $s['value'] !== 'completed')),
            'can' => ['record' => $user->can('production.consume')],
        ]);
    }

    public function photo(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', Rule::in(['lot', 'order', 'count'])],
            'subject_id' => ['required', 'integer'],
            'photo' => ['required', 'image', 'max:10240'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $subject = match ($data['subject_type']) {
            'lot' => InventoryLot::query()->findOrFail($data['subject_id']),
            'order' => ManufacturingOrder::query()->findOrFail($data['subject_id']),
            'count' => StockCount::query()->findOrFail($data['subject_id']),
        };

        $path = 'floor-photos/'.now()->format('Y/m').'/'.uniqid('', true).'.'.($request->file('photo')->getClientOriginalExtension() ?: 'jpg');
        Storage::disk('local')->put($path, (string) file_get_contents($request->file('photo')->getRealPath()));

        FloorPhoto::query()->create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'path' => $path,
            'note' => $data['note'] ?? null,
            'taken_by' => $request->user()->id,
            'taken_at' => now(),
        ]);

        return back()->withToast('success', 'Photo attached.');
    }

    /**
     * @return array<string, mixed>
     */
    private function resolve(string $code, Request $request): array
    {
        $parsed = ScanCode::parse($code);
        $user = $request->user();

        $tryLot = fn () => InventoryLot::query()->with(['item:id,code,name,type,stock_uom_id', 'item.stockUom:id,code', 'ownerClient:id,name'])->where('batch_number', $parsed['value'])->first();
        $tryOrder = fn () => ManufacturingOrder::query()->with('product:id,name')->where('number', $parsed['value'])->first();
        $tryItem = fn () => Item::query()->with('stockUom:id,code')->where('code', $parsed['value'])->first();
        $tryLocation = function () use ($parsed) {
            [$store, $loc] = array_pad(explode('/', $parsed['value'], 2), 2, null);
            $loc = $loc === '' ? null : $loc;
            $warehouse = Warehouse::query()->where('code', $store)->first();

            return $warehouse === null ? null : ($loc === null ? $warehouse : WarehouseLocation::query()->where('warehouse_id', $warehouse->id)->where('code', $loc)->with('warehouse:id,code,name')->first());
        };

        $found = match ($parsed['type']) {
            ScanCode::LOT => $tryLot(),
            ScanCode::ORDER => $tryOrder(),
            ScanCode::ITEM => $tryItem(),
            ScanCode::LOCATION => $tryLocation(),
            default => $tryLot() ?? $tryOrder() ?? $tryItem(),
        };

        if ($found instanceof InventoryLot) {
            $balances = StockBalance::query()->with('warehouse:id,code,name')->where('lot_id', $found->id)->where('on_hand', '>', 0)->get();

            return [
                'kind' => 'lot',
                'code' => ScanCode::lot($found->batch_number),
                'title' => $found->batch_number,
                'subtitle' => $found->item?->name,
                'lot' => [
                    'id' => $found->id, 'batch_number' => $found->batch_number, 'item' => $found->item?->name, 'item_code' => $found->item?->code,
                    'qc_status' => $found->qc_status->value, 'qc_label' => $found->qc_status->label(), 'expiry_at' => $found->expiry_at?->toDateString(),
                    'expired' => $found->isExpired(), 'owner' => $found->ownerClient?->name, 'unit' => $found->item?->stockUom?->code,
                    'on_hand' => Decimal::strip((string) $balances->sum('on_hand')),
                    'where' => $balances->map(fn ($b) => ['store' => $b->warehouse?->code, 'on_hand' => Decimal::strip($b->on_hand)])->values()->all(),
                ],
                'actions' => array_values(array_filter([
                    ['label' => 'Open batch', 'href' => route('lots.show', $found)],
                    $user->can('inventory.view') ? ['label' => 'Recall trace', 'href' => route('lots.trace', $found)] : null,
                    $found->qc_status->value === 'pending' && $user->can('qc.view') ? ['label' => 'QC checkpoint', 'href' => route('qc.index')] : null,
                ])),
            ];
        }

        if ($found instanceof ManufacturingOrder) {
            return [
                'kind' => 'order',
                'code' => ScanCode::order($found->number),
                'title' => $found->number,
                'subtitle' => $found->product?->name,
                'order' => ['id' => $found->id, 'number' => $found->number, 'product' => $found->product?->name, 'status' => $found->status->value, 'status_label' => $found->status->label(), 'stage' => $found->current_stage?->label(), 'progress' => (int) $found->stage_progress],
                'actions' => array_values(array_filter([
                    ['label' => 'Open order', 'href' => route('manufacturing.show', $found)],
                    $user->can('production.consume') && in_array($found->status, [ManufacturingOrderStatus::Approved, ManufacturingOrderStatus::InProgress], true) ? ['label' => 'Issue materials (scan)', 'href' => route('floor.issue', $found)] : null,
                    $user->can('production.consume') && $found->status === ManufacturingOrderStatus::InProgress ? ['label' => 'Record stage', 'href' => route('floor.production')] : null,
                ])),
            ];
        }

        if ($found instanceof Item) {
            return [
                'kind' => 'item',
                'code' => ScanCode::item($found->code),
                'title' => $found->name,
                'subtitle' => $found->code,
                'item' => ['id' => $found->id, 'code' => $found->code, 'name' => $found->name, 'type' => $found->type->value, 'unit' => $found->stockUom?->code, 'on_hand' => Decimal::strip((string) StockBalance::query()->where('item_id', $found->id)->sum('on_hand'))],
                'actions' => [['label' => 'Open material', 'href' => match ($found->type->value) {
                    'raw_material' => route('raw-materials.show', $found), 'packaging_material' => route('packaging-materials.show', $found), default => route('products.show', $found)
                }]],
            ];
        }

        if ($found instanceof WarehouseLocation) {
            return [
                'kind' => 'location',
                'code' => ScanCode::location($found->warehouse?->code ?? '', $found->code),
                'title' => $found->code,
                'subtitle' => ($found->warehouse?->name ?? '').' · '.$found->name,
                'location' => ['id' => $found->id, 'code' => $found->code, 'name' => $found->name, 'store' => $found->warehouse?->code],
                'actions' => [['label' => 'Open store', 'href' => route('stores.show', $found->warehouse_id)]],
            ];
        }

        if ($found instanceof Warehouse) {
            return [
                'kind' => 'store',
                'code' => ScanCode::location($found->code, ''),
                'title' => $found->name,
                'subtitle' => $found->code,
                'actions' => array_values(array_filter([
                    ['label' => 'Open store', 'href' => route('stores.show', $found)],
                    $user->can('inventory.count') ? ['label' => 'Start a count', 'href' => route('counts.index', ['warehouse' => $found->id])] : null,
                ])),
            ];
        }

        // A marketplace label: its AWB, tracking code or order number.
        if ($parsed['type'] === null && $user->can('marketplace.view')) {
            $parcel = Shipment::query()->matchingCode($parsed['value'])->with(['marketplace:id,name', 'lines'])->latest('id')->first();

            if ($parcel !== null) {
                return [
                    'kind' => 'parcel',
                    'code' => $code,
                    'title' => $parcel->reference(),
                    'subtitle' => "{$parcel->marketplace?->name} · {$parcel->status->label()}",
                    'actions' => array_values(array_filter([
                        $user->can('marketplace.pack') && $parcel->status->awaitsPacking() ? ['label' => 'Pack it', 'href' => route('floor.pack')] : null,
                        ['label' => 'Open the batch', 'href' => route('online-orders.show', $parcel->label_batch_id)],
                    ])),
                ];
            }
        }

        return ['kind' => 'unknown', 'code' => $code, 'title' => 'Not recognised', 'subtitle' => "Nothing matches \"{$parsed['value']}\".", 'actions' => []];
    }
}
