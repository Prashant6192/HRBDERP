<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Models\InventoryTransaction;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Corrections happen through controlled reversals, never by editing a
 * posting.
 */
class LedgerController extends Controller
{
    public function __construct(private readonly InventoryLedgerService $ledger) {}

    public function reverse(Request $request, InventoryTransaction $transaction): RedirectResponse
    {
        Gate::authorize('inventory.reverse');

        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        try {
            $reversal = $this->ledger->reverse($transaction, $data['reason'], $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->withToast('success', "{$transaction->number} reversed by {$reversal->number}; both stay in the ledger.");
    }
}
