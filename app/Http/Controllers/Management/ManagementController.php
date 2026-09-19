<?php

declare(strict_types=1);

namespace App\Http\Controllers\Management;

use App\Domain\Reporting\Services\ManagementDashboardService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The management view on a phone: read-only screens that answer the
 * questions the owner asks on the way to the factory. Anyone who may
 * see reports may see these.
 */
class ManagementController extends Controller
{
    public function __construct(private readonly ManagementDashboardService $dashboard) {}

    public function index(Request $request): Response
    {
        $this->guard($request);

        return Inertia::render('management/index', [
            'overview' => $this->dashboard->overview($request->user()),
        ]);
    }

    public function production(Request $request): Response
    {
        $this->guard($request);

        return Inertia::render('management/production', $this->dashboard->production($request->user()));
    }

    public function materials(Request $request): Response
    {
        $this->guard($request);

        return Inertia::render('management/materials', $this->dashboard->materials($request->user(), (string) $request->query('type', 'raw_material')));
    }

    public function ordering(Request $request): Response
    {
        $this->guard($request);

        return Inertia::render('management/ordering', $this->dashboard->ordering($request->user()));
    }

    public function formulas(Request $request): Response
    {
        $this->guard($request);

        return Inertia::render('management/formulas', $this->dashboard->formulas());
    }

    public function clients(Request $request): Response
    {
        $this->guard($request);

        return Inertia::render('management/clients', $this->dashboard->clients());
    }

    public function batches(Request $request): Response
    {
        $this->guard($request);

        return Inertia::render('management/batches', $this->dashboard->readyBatches($request->user()));
    }

    public function billing(Request $request): Response
    {
        $this->guard($request);

        return Inertia::render('management/billing', $this->dashboard->billing($request->user()));
    }

    private function guard(Request $request): void
    {
        abort_unless($request->user()->can('report.view'), 403, 'The management view is for those who may see reports.');
    }
}
