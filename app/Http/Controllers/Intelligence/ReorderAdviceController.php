<?php

declare(strict_types=1);

namespace App\Http\Controllers\Intelligence;

use App\Domain\Intelligence\DTOs\ItemOutlook;
use App\Domain\Intelligence\Services\StockOutlookService;
use App\Domain\MasterData\Enums\ItemType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Predictive reordering: what to order, how much, when and from whom —
 * before a material turns Low.
 */
class ReorderAdviceController extends Controller
{
    public function __construct(
        private readonly StockOutlookService $outlook,
        private readonly FacilityAccess $access,
    ) {}

    public function __invoke(Request $request): Response
    {
        Gate::authorize('purchase.view');

        $user = $request->user();
        $facilities = $this->access->facilitiesFor($user);
        $facility = $request->integer('facility') > 0 ? $facilities->firstWhere('id', $request->integer('facility')) : null;

        $type = in_array($request->string('type')->toString(), ['raw_material', 'packaging_material'], true)
            ? ItemType::from($request->string('type')->toString())
            : null;
        $all = $request->boolean('all');

        $advice = $this->outlook->reorderAdvice($facility, $type ? [$type] : null, onlyActionable: ! $all);

        return Inertia::render('purchase/reorder-advice', [
            'rows' => $advice->map(fn (ItemOutlook $o) => $o->toArray())->values()->all(),
            'counts' => [
                'order_today' => $advice->where('status', ItemOutlook::ORDER_TODAY)->count(),
                'order_soon' => $advice->where('status', ItemOutlook::ORDER_SOON)->count(),
                'watch' => $advice->where('status', ItemOutlook::WATCH)->count(),
            ],
            'filters' => ['facility' => $facility?->id, 'type' => $type?->value, 'all' => $all],
            'facilities' => $facilities->map(fn (Facility $f) => ['id' => $f->id, 'code' => $f->code, 'name' => $f->name])->values()->all(),
            'settings' => [
                'horizon_days' => (int) config('erp.intelligence.planning_horizon_days'),
                'window_days' => (int) config('erp.intelligence.consumption_window_days'),
                'default_lead_time_days' => (int) config('erp.intelligence.default_lead_time_days'),
            ],
        ]);
    }
}
