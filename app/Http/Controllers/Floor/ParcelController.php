<?php

declare(strict_types=1);

namespace App\Http\Controllers\Floor;

use App\Domain\Contract\Enums\ArtworkStatus;
use App\Domain\Contract\Services\ArtworkService;
use App\Domain\Marketplace\Enums\ShipmentStatus;
use App\Domain\Marketplace\Exceptions\OnlineOrderException;
use App\Domain\Marketplace\Models\HandoverSheet;
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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

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

    public function pack(Request $request): Response
    {
        $this->authorize('marketplace.pack');
        $user = $request->user();
        $stores = $this->stores($user);
        $today = Cutoff::today();

        $waiting = Shipment::query()
            ->whereIn('warehouse_id', $stores->modelKeys())
            ->whereIn('status', ShipmentStatus::awaitingPacking())
            ->count();

        $packedToday = Shipment::query()
            ->whereIn('warehouse_id', $stores->modelKeys())
            ->where('packed_at', '>=', $today->utc())
            ->count();

        $mine = Shipment::query()
            ->where('packed_by', $user->id)
            ->where('packed_at', '>=', $today->utc())
            ->with(['lines.item:id,code,name', 'marketplace:id,name'])
            ->latest('packed_at')
            ->limit(15)
            ->get();

        return Inertia::render('floor/pack', [
            'waiting' => $waiting,
            'packed_today' => $packedToday,
            'mine' => $mine->map(fn (Shipment $s) => OnlineOrderPresenter::shipment($s))->all(),
            'cutoff' => Cutoff::label(),
        ]);
    }

    /**
     * One scan at the packing table.
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
            $packed = $this->orders->pack($shipment, $user);
        } catch (OnlineOrderException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
                'shipment' => $this->describe($shipment->refresh()),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Packed. Stock taken out of '.$shipment->warehouse->name.'.',
            'shipment' => $this->describe($packed),
        ]);
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
            ->with(['lines.item:id,code,name', 'marketplace:id,name', 'packer:id,name'])
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
        $shipment->loadMissing(['lines.item:id,code,name', 'marketplace:id,name', 'brand:id,name,client_id', 'packer:id,name']);

        return [
            ...OnlineOrderPresenter::shipment($shipment),
            // The approved pack, so the packer can see it is the right bottle.
            'pictures' => $shipment->lines
                ->filter(fn ($l) => $l->item_id !== null)
                ->map(function ($l) use ($shipment): ?array {
                    $art = $this->artworks->forProduct($l->item_id, $shipment->brand?->client_id)
                        ->first(fn ($a) => $a->status === ArtworkStatus::Approved && $a->document_mime !== null && str_starts_with($a->document_mime, 'image/'));

                    return $art === null ? null : ['item_id' => $l->item_id, 'url' => route('artworks.document', $art)];
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
