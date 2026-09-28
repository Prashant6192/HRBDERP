<?php

declare(strict_types=1);

namespace App\Http\Controllers\Navigation;

use App\Domain\Navigation\Services\GlobalSearchService;
use App\Domain\Navigation\Services\NavigationCountService;
use App\Domain\Navigation\Services\PlaceService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the Rail + Search shell asks the server for: the numbers beside a
 * place's pages, and the records behind Ctrl K. Both answer only with
 * what the person may see.
 */
class NavigationController extends Controller
{
    public function __construct(
        private readonly PlaceService $places,
        private readonly NavigationCountService $counts,
        private readonly GlobalSearchService $search,
    ) {}

    public function counts(Request $request): JsonResponse
    {
        $key = $request->string('place')->toString();
        $place = collect($this->places->for($request->user())['places'])->firstWhere('key', $key);

        if ($place === null) {
            return response()->json(['counts' => []]);
        }

        return response()->json(['counts' => $this->counts->for($request->user(), $place)]);
    }

    public function search(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return response()->json(['groups' => $this->search->search($request->user(), (string) $request->query('q', ''))]);
    }
}
