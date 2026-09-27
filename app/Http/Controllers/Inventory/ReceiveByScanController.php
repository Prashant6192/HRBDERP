<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Exceptions\StockTransferException;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\StockTransferLine;
use App\Domain\Inventory\Models\TransferCartonScan;
use App\Domain\Inventory\Services\CartonReceivingService;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Receive by scan: the depot scans cartons off the lorry, and the transfer
 * books itself in at the last one.
 */
class ReceiveByScanController extends Controller
{
    public function __construct(
        private readonly CartonReceivingService $cartons,
        private readonly FacilityAccess $access,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('inventory.receive_transfer');
        $user = $request->user();

        $facilities = $this->access->facilitiesFor($user)->filter(fn (Facility $f) => $f->can_receive)->values();
        $facility = $facilities->firstWhere('id', $request->integer('facility'))
            ?? $facilities->firstWhere('id', $this->access->primaryFacility($user)?->id)
            ?? $facilities->first(fn (Facility $f) => ! $f->can_manufacture)
            ?? $facilities->first();

        $transfers = $facility === null ? collect() : $this->cartons->inbound($facility)
            ->with(['lines.item:id,code,name,stock_uom_id', 'lines.item.stockUom:id,code', 'lines.lot:id,batch_number,carton_plan', 'sourceFacility:id,name', 'destinationStore:id,code,name'])
            ->get();

        $scans = TransferCartonScan::query()->whereIn('stock_transfer_id', $transfers->pluck('id'))
            ->with(['scanner:id,name', 'lot:id,batch_number'])->latest('scanned_at')->latest('id')->get();

        return Inertia::render('receive/index', [
            'facilities' => $facilities->map(fn (Facility $f) => ['value' => $f->id, 'label' => $f->name])->all(),
            'facility' => $facility?->only(['id', 'code', 'name']),
            'transfers' => $transfers->map(function (StockTransfer $t) use ($scans) {
                $mine = $scans->where('stock_transfer_id', $t->id);

                return [
                    'id' => $t->id,
                    'number' => $t->number,
                    'status' => $t->status->value,
                    'status_label' => $t->status->label(),
                    'from' => $t->sourceFacility?->name,
                    'store' => $t->destinationStore?->name,
                    'dispatched_at' => $t->dispatched_at?->toIso8601String(),
                    'vehicle' => $t->vehicle_ref,
                    'verified' => $t->isVerified(),
                    'waiting' => $mine->whereNull('booked_at')->count(),
                    'lines' => $t->lines->map(function (StockTransferLine $l) use ($t, $mine) {
                        $perBox = $l->lot?->carton_plan['units_per_box'] ?? null;
                        $outstanding = $l->outstanding();

                        return [
                            'id' => $l->id,
                            'item' => $l->item?->name,
                            'code' => $l->item?->code,
                            'batch' => $l->lot?->batch_number,
                            'uom' => $l->item?->stockUom?->code,
                            'dispatched' => (string) $l->dispatched()->strippedOfTrailingZeros(),
                            'received' => (string) $l->received()->strippedOfTrailingZeros(),
                            'outstanding' => (string) $outstanding->strippedOfTrailingZeros(),
                            'scanned' => (string) $this->cartons->unbooked($t, $l->id)->strippedOfTrailingZeros(),
                            'cartons_scanned' => $mine->where('stock_transfer_line_id', $l->id)->whereNull('booked_at')->count(),
                            'cartons_expected' => $perBox ? (int) ceil((float) (string) $outstanding / (float) $perBox) : null,
                        ];
                    })->values()->all(),
                ];
            })->values()->all(),
            'log' => $scans->take(12)->map(fn (TransferCartonScan $s) => [
                'id' => $s->id,
                'batch' => $s->lot?->batch_number,
                'box' => $s->box_no,
                'units' => (string) $s->units()->strippedOfTrailingZeros(),
                'by' => $s->scanner?->name,
                'at' => $s->scanned_at->toIso8601String(),
                'booked' => $s->booked_at !== null,
            ])->values()->all(),
        ]);
    }

    public function scan(Request $request): RedirectResponse
    {
        Gate::authorize('inventory.receive_transfer');

        $data = $request->validate([
            'facility_id' => ['required', 'integer', 'exists:facilities,id'],
            'code' => ['required', 'string', 'max:255'],
            'units' => ['nullable', 'numeric', 'gt:0'],
        ]);

        $facility = Facility::query()->findOrFail($data['facility_id']);
        $this->access->assertCanWorkAt($request->user(), $facility);

        try {
            $result = $this->cartons->scan($request->user(), $facility, $data['code'], isset($data['units']) ? (string) $data['units'] : null, mb_substr((string) $request->userAgent(), 0, 160));
        } catch (StockTransferException|InsufficientStockException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        $scan = $result['scan'];
        $transfer = $result['transfer'];
        $message = $result['booked']
            ? "Last carton scanned. {$transfer->number} is booked into the store."
            : "Carton {$scan->box_no} of {$scan->lot?->batch_number} counted on {$transfer->number}.".($result['verified_now'] ? ' Consignment verified.' : '');

        return back()->withToast('success', $message);
    }

    public function book(Request $request, StockTransfer $transfer): RedirectResponse
    {
        Gate::authorize('receive', $transfer);
        $transfer->loadMissing('destinationFacility');

        if ($transfer->destinationFacility !== null) {
            $this->access->assertCanWorkAt($request->user(), $transfer->destinationFacility);
        }

        try {
            $transfer = $this->cartons->book($transfer, $request->user());
        } catch (StockTransferException|InsufficientStockException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return back()->withToast('success', "Booked in what was scanned on {$transfer->number} ({$transfer->status->label()}).");
    }
}
