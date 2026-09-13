<?php

declare(strict_types=1);

namespace App\Http\Controllers\Intelligence;

use App\Domain\Intelligence\Services\ExpiryRiskService;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ExpiryRiskController extends Controller
{
    public function __construct(
        private readonly ExpiryRiskService $risk,
        private readonly FacilityAccess $access,
    ) {}

    public function __invoke(Request $request): Response
    {
        Gate::authorize('inventory.view');

        $facilities = $this->access->facilitiesFor($request->user());
        $facility = $request->integer('facility') > 0 ? $facilities->firstWhere('id', $request->integer('facility')) : null;
        $window = in_array($request->integer('days'), [30, 60, 90, 180, 365], true) ? $request->integer('days') : null;

        return Inertia::render('stock/expiry-risk', [
            'report' => $this->risk->report($facility, $window),
            'filters' => ['facility' => $facility?->id, 'days' => $window ?? (int) config('erp.intelligence.expiry_risk_days')],
            'facilities' => $facilities->map(fn (Facility $f) => ['id' => $f->id, 'code' => $f->code, 'name' => $f->name])->values()->all(),
        ]);
    }
}
