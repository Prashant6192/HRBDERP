<?php

declare(strict_types=1);

namespace App\Http\Controllers\Intelligence;

use App\Domain\Intelligence\Services\ProductionAnalyticsService;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Consumption intelligence and yield analytics across batches.
 */
class ProductionAnalyticsController extends Controller
{
    public function __construct(
        private readonly ProductionAnalyticsService $analytics,
        private readonly FacilityAccess $access,
    ) {}

    public function __invoke(Request $request): Response
    {
        Gate::authorize('production.view');

        $facilities = $this->access->facilitiesFor($request->user());
        $facility = $request->integer('facility') > 0 ? $facilities->firstWhere('id', $request->integer('facility')) : null;
        $batches = in_array($request->integer('batches'), [3, 6, 12, 24], true) ? $request->integer('batches') : 6;
        $days = in_array($request->integer('days'), [30, 90, 180, 365], true) ? $request->integer('days') : 90;

        return Inertia::render('analytics/production', [
            'trends' => $this->analytics->consumptionTrends($facility, $batches),
            'yields' => $this->analytics->yieldByProduct($facility, $days),
            'filters' => ['facility' => $facility?->id, 'batches' => $batches, 'days' => $days],
            'facilities' => $facilities->map(fn (Facility $f) => ['id' => $f->id, 'code' => $f->code, 'name' => $f->name])->values()->all(),
            'thresholds' => [
                'variance_percent' => (float) config('erp.exceptions.material_variance_percent', 5),
                'yield_floor_percent' => (float) config('erp.exceptions.yield_floor_percent', 95),
            ],
        ]);
    }
}
