<?php

declare(strict_types=1);

namespace App\Http\Controllers\Floor;

use App\Domain\Contract\Enums\ArtworkStatus;
use App\Domain\Contract\Services\ArtworkService;
use App\Domain\Marketplace\Enums\ShipmentStatus;
use App\Domain\Marketplace\Exceptions\OnlineOrderException;
use App\Domain\Marketplace\Models\HandoverSheet;
use App\Domain\Marketplace\Models\LabelBatch;
use App\Domain\Marketplace\Models\Shipment;
use App\Domain\Marketplace\Services\HandoverSheetPdf;
use App\Domain\Marketplace\Services\OnlineOrderService;
use App\Domain\Marketplace\Support\Cutoff;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Warehouse;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Http\Controllers\Controller;
use App\Http\Controllers\OnlineOrders\OnlineOrderPresenter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The packing table and the courier's pickup, in the floor mode.
 *
 * Packing is one scan: the label's barcode finds the parcel, the screen
 * says in large letters what goes in it, and the stock leaves the store.
 * A cancelled order, a parcel already packed, a label the ERP has never
 * seen — each is stopped with the reason.
 */
class ParcelController extends Controller
{
    public function __construct(
        private readonly OnlineOrderService $orders,
        private readonly FacilityAccess $access,
        private readonly ArtworkService $artworks,
    ) {}

    /**
     * The floor's one scan screen: pick the day (today unless changed),
     * then scan every parcel as it is sealed.
     */
    public function pack(Request $request): Response
    {
        $this->authorize('marketplace.pack');
        $user = $request->user();
        $stores = $this->stores($user);
        $today = Cutoff::today();

        try {
            $day = $request->filled('date') ? CarbonImmutable::parse($request->string('date')->toString(), Cutoff::timezone())->startOfDay() : $today;
        } catch (Throwable) {
            $day = $today;
        }

        $ofDay = fn () => Shipment::query()
            ->whereIn('warehouse_id', $stores->modelKeys())
            ->whereIn('label_batch_id', LabelBatch::query()->select('id')->whereDate('for_date', $day->toDateString()));

        $counts = $ofDay()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->toBase()->pluck('n', 'status')->map(fn ($n) => (int) $n);

        $mine = Shipment::query()
            ->where('packed_by', $user->id)
            ->where('packed_at', '>=', $today->utc())
            ->with(['lines.item:id,code,name', 'picks.item:id,code,name', 'picks.line:id,seller_sku', 'marketplace:id,name'])
            ->latest('packed_at')
            ->limit(15)
            ->get();

        return Inertia::render('floor/pack', [
            'date' => $day->toDateString(),
            'is_today' => $day->equalTo($today),
            'counts' => [
                'to_scan' => (int) $counts->only(ShipmentStatus::awaitingPacking())->sum(),
                'scanned' => (int) ($counts[ShipmentStatus::Packed->value] ?? 0) + (int) ($counts[ShipmentStatus::HandedOver->value] ?? 0),
                'dispatched' => (int) ($counts[ShipmentStatus::HandedOver->value] ?? 0),
                'cancelled' => (int) ($counts[ShipmentStatus::Cancelled->value] ?? 0),
            ],
            'mine' => $mine->map(fn (Shipment $s) => OnlineOrderPresenter::shipment($s))->all(),
            'cutoff' => Cutoff::label(),
            'can_cancel' => $user->can('marketplace.pack') || $user->can('marketplace.print') || $user->can('marketplace.manage'),
        ]);
    }

    /**
     * One scan: packed and on the courier's pile, or "already scanned".
     */
    public function scan(Request $request): JsonResponse
    {
        $this->authorize('marketplace.pack');
        $user = $request->user();
        $data = $request->validate(['code' => ['required', 'string', 'max:255']]);

        $shipment = $this->orders->findByCode($data['code']);

        if ($shipment === null) {
            return response()->json([
                'ok' => false,
                'message' => "No parcel has the code {$data['code']}. Was its label uploaded? Put it aside and tell the office.",
            ], 404);
        }

        if (! $this->access->canWorkIn($user, $shipment->warehouse)) {
            return response()->json([
                'ok' => false,
                'message' => "This parcel ships from {$shipment->warehouse->name}, not from a store you work in.",
                'shipment' => $this->describe($shipment),
            ], 403);
        }

        try {
            ['result' => $result, 'shipment' => $scanned] = $this->orders->scanOut($shipment, $user);
        } catch (OnlineOrderException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
                'shipment' => $this->describe($shipment->refresh()),
            ], 422);
        }

        $tz = Cutoff::timezone();

        return response()->json([
            'ok' => true,
            'result' => $result,
            'message' => match ($result) {
                'already' => sprintf(
                    'Already scanned%s at %s. If this is a second parcel for the same label, it is a duplicate: open it and put the goods back on the shelf.',
                    $this->by($scanned->packer?->name),
                    $scanned->packed_at?->timezone($tz)->format('j M, g:i A'),
                ),
                'dispatched' => sprintf(
                    'Already dispatched with the courier%s. If the courier left it, press Order cancelled.',
                    $scanned->handed_over_at ? ' on '.$scanned->handed_over_at->timezone($tz)->format('j M, g:i A') : '',
                ),
                default => 'Packed. Stock taken out of '.$shipment->warehouse->name.'. Put it on the '.($scanned->courier ?? 'courier').' pile.',
            },
            'shipment' => $this->describe($scanned),
        ]);
    }

    /**
     * The courier found the order cancelled: stock back on the shelf.
     */
    public function cancelInHand(Request $request, Shipment $shipment): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('marketplace.pack') || $user->can('marketplace.print') || $user->can('marketplace.manage'), 403);
        abort_unless($this->access->canWorkIn($user, $shipment->warehouse), 403);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        try {
            $parcel = $this->orders->cancel($shipment, $data['reason'] ?? 'Cancelled on the marketplace; found at pickup', $user);
        } catch (OnlineOrderException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Cancelled. The goods are back in the ERP: open the parcel and put them back on the shelf.',
            'shipment' => $this->describe($parcel),
        ]);
    }

    private function by(?string $name): string
    {
        return $name ? " by {$name}" : '';
    }

    public function handover(Request $request): Response
    {
        $this->authorize('marketplace.handover');
        $user = $request->user();
        $stores = $this->stores($user);
        $storeId = $request->integer('store') ?: $stores->first()?->id;
        $store = $stores->firstWhere('id', $storeId);

        $packed = $store === null ? collect() : Shipment::query()
            ->where('warehouse_id', $store->id)
            ->where('status', ShipmentStatus::Packed->value)
            ->with(['lines.item:id,code,name', 'picks.item:id,code,name', 'picks.line:id,seller_sku', 'marketplace:id,name', 'packer:id,name'])
            ->orderByRaw("COALESCE(courier, '~')")
            ->orderBy('packed_at')
            ->get();

        $sheets = $store === null ? [] : HandoverSheet::query()
            ->where('warehouse_id', $store->id)
            ->where('handed_over_at', '>=', Cutoff::today()->subDays(2)->utc())
            ->with('handedOverBy:id,name')
            ->latest('id')
            ->limit(15)
            ->get()
            ->map(fn (HandoverSheet $h) => [
                'id' => $h->id, 'number' => $h->number, 'courier' => $h->courier, 'count' => $h->shipment_count,
                'by' => $h->handedOverBy?->name, 'received_by' => $h->received_by_name, 'at' => $h->handed_over_at->toIso8601String(),
                'pdf' => route('handover-sheets.pdf', $h),
            ])
            ->all();

        return Inertia::render('floor/handover', [
            'stores' => $stores->map(fn (Warehouse $w) => ['value' => (string) $w->id, 'label' => "{$w->facility?->name} · {$w->name}"])->values()->all(),
            'store' => $store?->id,
            'parcels' => $packed->map(fn (Shipment $s) => OnlineOrderPresenter::shipment($s))->all(),
            'sheets' => $sheets,
            'just_made' => $request->integer('sheet') ?: null,
        ]);
    }

    public function storeHandover(Request $request): RedirectResponse
    {
        $this->authorize('marketplace.handover');
        $user = $request->user();

        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'courier' => ['required', 'string', 'max:64'],
            'shipment_ids' => ['required', 'array', 'min:1'],
            'shipment_ids.*' => ['integer'],
            'received_by' => ['nullable', 'string', 'max:255'],
        ], ['shipment_ids.required' => 'Tick the parcels the courier is taking.']);

        $store = Warehouse::query()->findOrFail($data['warehouse_id']);
        abort_unless($this->access->canWorkIn($user, $store), 403);

        try {
            $sheet = $this->orders->handOver($store, $data['courier'], array_map('intval', $data['shipment_ids']), $user, $data['received_by'] ?? null);
        } catch (OnlineOrderException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return to_route('floor.handover', ['store' => $store->id, 'sheet' => $sheet->id])
            ->withToast('success', "{$sheet->number}: {$sheet->shipment_count} parcel(s) handed to {$sheet->courier}. Print the sheet for the courier to sign.");
    }

    public function sheet(Request $request, HandoverSheet $sheet, HandoverSheetPdf $pdf): HttpResponse
    {
        $user = $request->user();
        abort_unless($user->can('marketplace.handover') || $user->can('marketplace.manage') || $user->can('marketplace.print'), 403);
        abort_unless($this->access->canWorkAt($user, $sheet->facility_id), 403);

        return $pdf->render($sheet)->stream($pdf->filename($sheet));
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Shipment $shipment): array
    {
        $shipment->loadMissing(['lines.item:id,code,name', 'picks.item:id,code,name', 'picks.line:id,seller_sku', 'marketplace:id,name', 'brand:id,name,client_id', 'packer:id,name', 'handedOverBy:id,name']);

        return [
            ...OnlineOrderPresenter::shipment($shipment),
            // The approved pack, so the packer can see it is the right bottle.
            'pictures' => $shipment->picks
                ->pluck('item_id')
                ->unique()
                ->map(function (int $itemId) use ($shipment): ?array {
                    $art = $this->artworks->forProduct($itemId, $shipment->brand?->client_id)
                        ->first(fn ($a) => $a->status === ArtworkStatus::Approved && $a->document_mime !== null && str_starts_with($a->document_mime, 'image/'));

                    return $art === null ? null : ['item_id' => $itemId, 'url' => route('artworks.document', $art)];
                })
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /**
     * The finished goods stores this person packs in.
     *
     * @return Collection<int, Warehouse>
     */
    private function stores(User $user): Collection
    {
        return $this->access->scopeStores($user, Warehouse::query())
            ->where('type', WarehouseType::FinishedGoods->value)
            ->where('is_active', true)
            ->where('is_system', false)
            ->whereHas('facility', fn ($q) => $q->where('can_dispatch', true))
            ->with('facility:id,name')
            ->orderBy('name')
            ->get();
    }
}
