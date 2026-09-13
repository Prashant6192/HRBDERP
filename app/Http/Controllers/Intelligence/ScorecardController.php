<?php

declare(strict_types=1);

namespace App\Http\Controllers\Intelligence;

use App\Domain\Intelligence\Services\ScorecardService;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Department scorecards, OTIF and process performance.
 */
class ScorecardController extends Controller
{
    public function __construct(
        private readonly ScorecardService $scorecards,
        private readonly FacilityAccess $access,
    ) {}

    public function __invoke(Request $request): Response
    {
        Gate::authorize('report.view');

        $facilities = $this->access->facilitiesFor($request->user());
        $facility = $request->integer('facility') > 0 ? $facilities->firstWhere('id', $request->integer('facility')) : null;
        $days = in_array($request->integer('days'), [7, 30, 90, 365], true) ? $request->integer('days') : 30;

        return Inertia::render('analytics/scorecards', [
            'scorecards' => $this->scorecards->build($facility, $days),
            'filters' => ['facility' => $facility?->id, 'days' => $days],
            'facilities' => $facilities->map(fn (Facility $f) => ['id' => $f->id, 'code' => $f->code, 'name' => $f->name])->values()->all(),
        ]);
    }
}
