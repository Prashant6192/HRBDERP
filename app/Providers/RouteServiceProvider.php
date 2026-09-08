<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\MasterData\Models\PackagingMaterial;
use App\Domain\MasterData\Models\Product;
use App\Domain\MasterData\Models\RawMaterial;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * Route parameters for the three item modules are bound to their concrete
     * models rather than to the shared Item.
     *
     * This matters: all three live in one table, so binding to Item would let
     * /products/17 resolve a raw material and render it under the products
     * screen. Binding to the subclass applies its type scope, and a mismatched
     * id is a 404 — which is what it is.
     *
     * @var array<string, class-string>
     */
    private const ITEM_BINDINGS = [
        'raw_material' => RawMaterial::class,
        'packaging_material' => PackagingMaterial::class,
        'product' => Product::class,
    ];

    public function boot(): void
    {
        foreach (self::ITEM_BINDINGS as $parameter => $model) {
            Route::bind(
                $parameter,
                static fn (string $value) => $model::query()->findOrFail($value),
            );
        }
    }
}
