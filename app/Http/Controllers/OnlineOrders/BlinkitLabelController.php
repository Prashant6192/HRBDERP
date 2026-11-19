<?php

declare(strict_types=1);

namespace App\Http\Controllers\OnlineOrders;

use App\Domain\Marketplace\Models\BlinkitProduct;
use App\Domain\Marketplace\Services\BlinkitLabelService;
use App\Http\Controllers\Controller;
use App\Support\Barcode\Code128;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Blinkit Labels: the products sold on Blinkit, kept once, and their SKU
 * labels (50 × 25 mm, two to a row) and box labels (4 × 6 inch) printed
 * with the day's dates.
 */
class BlinkitLabelController extends Controller
{
    public function __construct(private readonly BlinkitLabelService $labels) {}

    public function index(Request $request): Response
    {
        $this->authorize('marketplace.view');
        $user = $request->user();

        return Inertia::render('blinkit/labels', [
            'products' => BlinkitProduct::query()->orderByDesc('is_active')->orderBy('product_name')->get()
                ->map(fn (BlinkitProduct $p) => [
                    'id' => $p->id,
                    'brand_name' => $p->brand_name,
                    'product_name' => $p->product_name,
                    'upc' => $p->upc,
                    'pid' => $p->pid,
                    'mrp' => rtrim(rtrim(number_format((float) $p->mrp, 2, '.', ''), '0'), '.'),
                    'uom' => $p->uom,
                    'units_in_box' => $p->units_in_box,
                    'is_active' => $p->is_active,
                    // The barcode's bars as shares of its width, to draw it on screen.
                    'bars' => $this->bars($p->upc),
                ])->all(),
            'most' => BlinkitLabelService::MOST,
            'can' => [
                'manage' => $user->can('marketplace.manage'),
                'print' => $user->can('marketplace.print') || $user->can('marketplace.manage'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('marketplace.manage');
        $product = BlinkitProduct::create([...$this->validated($request), 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);

        return back()->withToast('success', "{$product->product_name} added.");
    }

    public function update(Request $request, BlinkitProduct $blinkitProduct): RedirectResponse
    {
        $product = $blinkitProduct;
        $this->authorize('marketplace.manage');
        $product->fill([...$this->validated($request, $product), 'updated_by' => $request->user()->id])->save();

        return back()->withToast('success', "{$product->product_name} saved.");
    }

    public function print(Request $request, BlinkitProduct $blinkitProduct): HttpResponse
    {
        $product = $blinkitProduct;
        abort_unless($request->user()->can('marketplace.print') || $request->user()->can('marketplace.manage'), 403);

        $data = $request->validate([
            'type' => ['required', Rule::in([BlinkitLabelService::SKU, BlinkitLabelService::BOX])],
            'mfg' => ['nullable', 'date_format:Y-m'],
            'exp' => ['nullable', 'date_format:Y-m'],
            'copies' => ['required', 'integer', 'min:1', 'max:'.BlinkitLabelService::MOST],
        ]);

        $month = fn (?string $v) => empty($v) ? null : CarbonImmutable::createFromFormat('!Y-m', $v);

        try {
            $pdf = $this->labels->pdf($product, $data['type'], $month($data['mfg'] ?? null), $month($data['exp'] ?? null), (int) $data['copies']);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return $pdf->stream($this->labels->filename($product, $data['type']));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?BlinkitProduct $product = null): array
    {
        $data = $request->validate([
            'brand_name' => ['required', 'string', 'max:100'],
            'product_name' => ['required', 'string', 'max:255'],
            // Printed as a Code 128 barcode: plain letters, digits and signs.
            'upc' => ['required', 'string', 'max:32', 'regex:/^[\x20-\x7E]+$/', Rule::unique('blinkit_products', 'upc')->ignore($product?->id)],
            'pid' => ['nullable', 'string', 'max:32'],
            'mrp' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'uom' => ['nullable', 'string', 'max:32'],
            'units_in_box' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'upc.regex' => 'The UPC can hold only plain letters, digits and signs, as a barcode carries them.',
            'upc.unique' => 'Another product already has this UPC.',
        ]);

        return [...$data, 'upc' => trim($data['upc'])];
    }

    /**
     * @return list<array{0: float, 1: float}>|null
     */
    private function bars(string $upc): ?array
    {
        try {
            return Code128::bars($upc, 100.0);
        } catch (Throwable) {
            return null;
        }
    }
}
