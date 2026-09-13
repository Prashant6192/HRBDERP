<?php

declare(strict_types=1);

namespace App\Http\Controllers\Intelligence;

use App\Domain\Intelligence\Services\CapacityService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CapacityController extends Controller
{
    public function __construct(private readonly CapacityService $capacity) {}

    public function __invoke(Request $request): Response
    {
        Gate::authorize('planning.view');

        $weeks = in_array($request->integer('weeks'), [4, 6, 8, 12], true) ? $request->integer('weeks') : 6;

        return Inertia::render('planning/capacity', [
            'facilities' => $this->capacity->utilisation($weeks),
            'filters' => ['weeks' => $weeks],
        ]);
    }
}
