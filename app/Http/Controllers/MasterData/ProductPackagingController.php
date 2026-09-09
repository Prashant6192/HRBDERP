<?php

declare(strict_types=1);

namespace App\Http\Controllers\MasterData;

use App\Domain\MasterData\Models\Product;
use App\Domain\Planning\Models\ProductPackagingLine;
use App\Http\Controllers\Controller;
use App\Http\Requests\Planning\StoreProductPackagingLineRequest;
use Illuminate\Http\RedirectResponse;

/**
 * A product's packaging list, edited from the product screen.
 */
class ProductPackagingController extends Controller
{
    public function store(StoreProductPackagingLineRequest $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        $product->packagingLines()->create($request->validated());

        return back()->withToast('success', 'Packaging line added.');
    }

    public function destroy(Product $product, ProductPackagingLine $line): RedirectResponse
    {
        $this->authorize('update', $product);

        abort_unless($line->product_id === $product->id, 404);

        $line->delete();

        return back()->withToast('success', 'Packaging line removed.');
    }
}
