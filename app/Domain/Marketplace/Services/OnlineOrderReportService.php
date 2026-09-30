<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\Enums\ShipmentStatus;
use App\Domain\Marketplace\Models\Shipment;
use App\Domain\Marketplace\Support\Cutoff;
use App\Support\Math\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The depot's online orders over a stretch of days, read from the scans:
 * what was uploaded, scanned out, left behind or cancelled — by day, by
 * courier and by brand — how many pieces of each product went out, who
 * scanned, and the parcels someone should look at again.
 */
final class OnlineOrderReportService
{
    /**
     * @param  list<int>|null  $facilityIds  null for every facility
     * @param  list<int>|null  $brandIds  null for every brand
     * @return array<string, mixed>
     */
    public function report(CarbonImmutable $from, CarbonImmutable $to, ?array $facilityIds = null, ?array $brandIds = null): array
    {
        $parcels = fn (): Builder => Shipment::query()
            ->join('label_batches as b', 'b.id', '=', 'shipments.label_batch_id')
            ->whereBetween('b.for_date', [$from->toDateString(), $to->toDateString()])
            ->when($facilityIds !== null, fn ($q) => $q->whereIn('b.facility_id', $facilityIds))
            ->when($brandIds !== null, fn ($q) => $q->whereIn('shipments.brand_id', $brandIds));

        $counts = fn (string $by, string $select): Collection => $parcels()
            ->selectRaw("{$select} AS k, shipments.status, COUNT(*) AS n")
            ->groupBy('k', 'shipments.status')
            ->toBase()
            ->get()
            ->groupBy('k')
            ->map(fn (Collection $rows, $k) => $this->row((string) $k, $rows->pluck('n', 'status')->map(fn ($n) => (int) $n)));

        $days = $counts('day', 'b.for_date')->sortKeys()->values();
        $couriers = $counts('courier', "COALESCE(NULLIF(shipments.courier, ''), 'Courier not read')")->sortByDesc('total')->values();
        $brands = $parcels()
            ->join('brands as br', 'br.id', '=', 'shipments.brand_id')
            ->selectRaw('br.name AS k, shipments.status, COUNT(*) AS n')
            ->groupBy('k', 'shipments.status')
            ->toBase()->get()->groupBy('k')
            ->map(fn (Collection $rows, $k) => $this->row((string) $k, $rows->pluck('n', 'status')->map(fn ($n) => (int) $n)))
            ->sortByDesc('total')->values();

        // Pieces that left the shelf: packed and not cancelled since.
        $products = $parcels()
            ->join('shipment_picks as sp', 'sp.shipment_id', '=', 'shipments.id')
            ->join('items as i', 'i.id', '=', 'sp.item_id')
            ->leftJoin('uoms as u', 'u.id', '=', 'i.stock_uom_id')
            ->whereIn('shipments.status', [ShipmentStatus::Packed->value, ShipmentStatus::HandedOver->value])
            ->groupBy('i.id', 'i.code', 'i.name', 'u.code')
            ->selectRaw('i.code, i.name, u.code AS unit, SUM(sp.units) AS pieces, COUNT(DISTINCT shipments.id) AS parcels')
            ->orderByDesc(DB::raw('SUM(sp.units)'))
            ->toBase()->get()
            ->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'unit' => $r->unit, 'pieces' => Decimal::strip((string) $r->pieces), 'parcels' => (int) $r->parcels])
            ->values();

        $scanners = $parcels()
            ->join('users as u', 'u.id', '=', 'shipments.handed_over_by')
            ->where('shipments.status', ShipmentStatus::HandedOver->value)
            ->groupBy('u.id', 'u.name')
            ->selectRaw('u.name, COUNT(*) AS n, MIN(shipments.handed_over_at) AS first_at, MAX(shipments.handed_over_at) AS last_at')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->toBase()->get()
            ->map(fn ($r) => ['name' => $r->name, 'scanned' => (int) $r->n, 'first' => $this->time($r->first_at), 'last' => $this->time($r->last_at)])
            ->values();

        $lookAgain = $parcels()
            ->whereIn('shipments.status', [ShipmentStatus::Cancelled->value, ShipmentStatus::Packed->value])
            ->with(['brand:id,name', 'canceller:id,name'])
            ->select('shipments.*', 'b.for_date as batch_date')
            ->orderBy('b.for_date')->orderBy('shipments.courier')
            ->limit(1000)
            ->get()
            ->map(fn (Shipment $s) => [
                'date' => (string) $s->getAttribute('batch_date'),
                'awb' => $s->awb,
                'order' => $s->order_number,
                'courier' => $s->courier ?? 'Courier not read',
                'brand' => $s->brand?->name,
                'what' => $s->status === ShipmentStatus::Cancelled ? 'Cancelled' : 'Left behind',
                'reason' => $s->cancel_reason,
                'by' => $s->status === ShipmentStatus::Cancelled ? $s->canceller?->name : null,
                'at' => $s->status === ShipmentStatus::Cancelled ? $this->time($s->cancelled_at) : null,
                'stock_back' => $s->status === ShipmentStatus::Cancelled && $s->packed_at !== null,
            ])
            ->values();

        $totals = $this->row('Total', collect([
            ...$days->reduce(function (array $carry, array $d) {
                foreach (['uploaded', 'to_scan', 'scanned', 'left_behind', 'cancelled', 'returned'] as $k) {
                    $carry[$k] = ($carry[$k] ?? 0) + $d[$k];
                }

                return $carry;
            }, []),
        ]), fromTotals: true);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => $totals,
            'days' => $days->all(),
            'couriers' => $couriers->all(),
            'brands' => $brands->all(),
            'products' => $products->all(),
            'scanners' => $scanners->all(),
            'look_again' => $lookAgain->all(),
        ];
    }

    /**
     * @param  Collection<string, int>  $by  count per status
     * @return array{key: string, uploaded: int, to_scan: int, scanned: int, left_behind: int, cancelled: int, returned: int, total: int}
     */
    private function row(string $key, Collection $by, bool $fromTotals = false): array
    {
        if ($fromTotals) {
            $r = ['key' => $key, ...array_map('intval', $by->all())];
            $r += ['uploaded' => 0, 'to_scan' => 0, 'scanned' => 0, 'left_behind' => 0, 'cancelled' => 0, 'returned' => 0];
            $r['total'] = $r['uploaded'];

            return $r;
        }

        $n = fn (ShipmentStatus ...$s) => (int) collect($s)->sum(fn (ShipmentStatus $x) => $by->get($x->value, 0));
        $uploaded = (int) $by->sum();

        return [
            'key' => $key,
            'uploaded' => $uploaded,
            'to_scan' => $n(ShipmentStatus::Uploaded, ShipmentStatus::Printed),
            'scanned' => $n(ShipmentStatus::HandedOver),
            'left_behind' => $n(ShipmentStatus::Packed),
            'cancelled' => $n(ShipmentStatus::Cancelled),
            'returned' => $n(ShipmentStatus::Returned),
            'total' => $uploaded,
        ];
    }

    private function time(mixed $at): ?string
    {
        return $at === null ? null : CarbonImmutable::parse((string) $at, 'UTC')->timezone(Cutoff::timezone())->format('j M, g:i A');
    }
}
