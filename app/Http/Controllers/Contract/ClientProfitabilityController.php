<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contract;

use App\Domain\Contract\Services\ClientProfitabilityService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Which third-party customers are actually profitable.
 */
class ClientProfitabilityController extends Controller
{
    public function __construct(private readonly ClientProfitabilityService $profitability) {}

    public function __invoke(Request $request): Response
    {
        Gate::authorize('costing.view');

        $days = in_array($request->integer('days'), [30, 90, 180, 365, 730], true) ? $request->integer('days') : 365;

        return Inertia::render('clients/profitability', [
            'clients' => $this->profitability->byClient($days),
            'filters' => ['days' => $days],
        ]);
    }
}
