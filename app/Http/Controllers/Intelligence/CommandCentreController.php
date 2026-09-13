<?php

declare(strict_types=1);

namespace App\Http\Controllers\Intelligence;

use App\Domain\Intelligence\Services\CommandCentreService;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The factory command centre: what is running, what is delayed, what is
 * waiting for QC, what is short, what is dispatching today, what requires
 * approval, and what could stop production tomorrow.
 */
class CommandCentreController extends Controller
{
    public function __construct(
        private readonly CommandCentreService $centre,
        private readonly FacilityAccess $access,
    ) {}

    public function __invoke(Request $request): Response
    {
        Gate::authorize('report.view');

        $facilities = $this->access->facilitiesFor($request->user());
        $facility = $request->integer('facility') > 0 ? $facilities->firstWhere('id', $request->integer('facility')) : null;

        return Inertia::render('command-centre', [
            'snapshot' => $this->centre->snapshot($facility),
            'filters' => ['facility' => $facility?->id],
            'facilities' => $facilities->map(fn (Facility $f) => ['id' => $f->id, 'code' => $f->code, 'name' => $f->name])->values()->all(),
            'timezone' => config('erp.company.timezone'),
        ]);
    }
}
