<?php

declare(strict_types=1);

namespace App\Http\Controllers\Intelligence;

use App\Domain\Intelligence\Services\SlowMovingStockService;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SlowMovingStockController extends Controller
{
    public function __construct(
        private readonly SlowMovingStockService $slow,
        private readonly FacilityAccess $access,
    ) {}

    public function __invoke(Request $request): Response
    {
        Gate::authorize('inventory.view');

        $facilities = $this->access->facilitiesFor($request->user());
        $facility = $request->integer('facility') > 0 ? $facilities->firstWhere('id', $request->integer('facility')) : null;
        $minimum = in_array($request->integer('days'), $this->slow->thresholds(), true) ? $request->integer('days') : min($this->slow->thresholds());

        return Inertia::render('stock/slow-moving', [
            'report' => $this->slow->report($facility, $minimum),
            'thresholds' => $this->slow->thresholds(),
            'filters' => ['facility' => $facility?->id, 'days' => $minimum],
            'facilities' => $facilities->map(fn (Facility $f) => ['id' => $f->id, 'code' => $f->code, 'name' => $f->name])->values()->all(),
        ]);
    }
}
