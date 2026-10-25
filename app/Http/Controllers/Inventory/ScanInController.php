<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Exceptions\ScanInException;
use App\Domain\Inventory\Services\ScanInService;
use App\Domain\Warehousing\Models\Warehouse;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Stock → a finished goods store → Scan in stock: scan or pick products,
 * give the dates, book them onto the shelf.
 */
class ScanInController extends Controller
{
    public function __construct(
        private readonly ScanInService $scanIn,
        private readonly FacilityAccess $access,
    ) {}

    public function lookup(Request $request): JsonResponse
    {
        Gate::authorize('inventory.receive_transfer');
        $code = trim($request->string('code')->toString());
        $found = $this->scanIn->findBarcode($code);

        return $found === null
            ? response()->json(['message' => "Barcode {$code} is not on any product yet. Choose the product and press Add: the barcode is kept for next time."], 404)
            : response()->json($found);
    }

    public function book(Request $request, Warehouse $warehouse): RedirectResponse
    {
        Gate::authorize('inventory.receive_transfer');
        $user = $request->user();
        abort_unless($this->access->canWorkIn($user, $warehouse), 403, "You are not assigned to {$warehouse->name}.");

        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.pack' => ['required', 'integer', 'min:1', 'max:12'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.came_in' => ['required', 'date'],
            'lines.*.manufactured_at' => ['nullable', 'date'],
            'lines.*.expiry_at' => ['nullable', 'date'],
            'lines.*.barcode' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $result = $this->scanIn->book($warehouse, $data['lines'], $user);
        } catch (ScanInException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        $numbers = collect($result['transactions'])->pluck('number')->implode(', ');
        $message = "{$result['pieces']} piece(s) booked into {$warehouse->name} ({$numbers}).";

        if ($result['packs_added'] !== []) {
            $message .= ' New product: '.implode(', ', $result['packs_added']).'. Map its pack listings on SKU mapping.';
        }

        if ($result['rehold'] > 0) {
            $message .= " {$result['rehold']} waiting parcel(s) now have their stock.";
        }

        return back()->withToast('success', $message);
    }
}
