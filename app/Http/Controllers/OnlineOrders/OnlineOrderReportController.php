<?php

declare(strict_types=1);

namespace App\Http\Controllers\OnlineOrders;

use App\Domain\Marketplace\Services\BrandAccess;
use App\Domain\Marketplace\Services\OnlineOrderReportService;
use App\Domain\Marketplace\Support\Cutoff;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Exports\OnlineOrdersReportExport;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * The online orders report: a day or a stretch of days, on screen or as
 * an Excel workbook.
 */
class OnlineOrderReportController extends Controller
{
    public function __construct(
        private readonly OnlineOrderReportService $reports,
        private readonly FacilityAccess $facilities,
        private readonly BrandAccess $brands,
    ) {}

    public function index(Request $request): Response
    {
        [$from, $to, $facilityIds, $facility] = $this->scope($request);

        return Inertia::render('online-orders/report', [
            'report' => $this->reports->report($from, $to, $facilityIds, $this->brands->brandIds($request->user())),
            'facility' => $facility,
            'facilities' => $this->choices($request->user()),
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        [$from, $to, $facilityIds] = $this->scope($request);
        $report = $this->reports->report($from, $to, $facilityIds, $this->brands->brandIds($request->user()));

        $name = 'online-orders-'.$from->toDateString().($from->equalTo($to) ? '' : '-to-'.$to->toDateString()).'.xlsx';

        return Excel::download(new OnlineOrdersReportExport($report), $name);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: list<int>|null, 3: int|null}
     */
    private function scope(Request $request): array
    {
        $user = $request->user();
        abort_unless($user->can('marketplace.view') && ! $this->brands->isRestricted($user), 403);

        $today = Cutoff::today();
        $day = function (string $key) use ($request, $today): CarbonImmutable {
            try {
                return $request->filled($key) ? CarbonImmutable::parse($request->string($key)->toString(), Cutoff::timezone())->startOfDay() : $today;
            } catch (Throwable) {
                return $today;
            }
        };

        $from = $day('from');
        $to = $day('to');

        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }

        // At most a year at a time.
        if ($from->diffInDays($to) > 366) {
            $from = $to->subDays(366);
        }

        $allowed = $this->facilities->facilityIds($user);
        $facility = $request->integer('facility') ?: null;

        if ($facility !== null && $allowed !== null && ! in_array($facility, $allowed, true)) {
            $facility = null;
        }

        return [$from, $to, $facility !== null ? [$facility] : $allowed, $facility];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function choices(User $user): array
    {
        return $this->facilities->scopeFacilities($user, Facility::query())
            ->active()->where('can_dispatch', true)->ordered()->get(['id', 'name'])
            ->map(fn (Facility $f) => ['value' => (string) $f->id, 'label' => $f->name])
            ->values()->all();
    }
}
