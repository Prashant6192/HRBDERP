<?php

declare(strict_types=1);

namespace App\Http\Controllers\MasterData;

use App\Domain\Contract\Enums\ArtworkStatus;
use App\Domain\Contract\Models\ClientArtwork;
use App\Domain\Contract\Services\ArtworkService;
use App\Domain\MasterData\Models\Product;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contract\StoreArtworkRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The company's own artwork for a product: the tube, label and carton
 * the packing line must match for every batch of it. A third-party
 * client's artwork stays on the client's page; this is the own brand's.
 */
class ProductArtworkController extends Controller
{
    public function __construct(private readonly ArtworkService $artworks) {}

    public function store(StoreArtworkRequest $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        $data = $request->validated();
        $artwork = $this->artworks->record(null, $product->id, $data, $request->file('document'), $request->user()->id);

        return back()->withToast('success', "Artwork {$artwork->title} v{$artwork->version} recorded".($artwork->status === ArtworkStatus::Approved ? ' as approved.' : '; awaiting approval.'));
    }

    public function status(Request $request, Product $product, ClientArtwork $artwork): RedirectResponse
    {
        $this->authorize('update', $product);
        $this->assertOwn($product, $artwork);

        $data = $request->validate([
            'status' => ['required', Rule::in(array_map(fn (ArtworkStatus $s) => $s->value, ArtworkStatus::cases()))],
            'approved_at' => ['nullable', 'date', 'required_if:status,approved'],
            'approved_by_name' => ['nullable', 'string', 'max:255'],
        ], ['approved_at.required_if' => 'Give the date the artwork was approved.']);

        $status = ArtworkStatus::from($data['status']);
        $this->artworks->setStatus($artwork, $status, $data['approved_at'] ?? null, $data['approved_by_name'] ?? null, $request->user()->id);

        return back()->withToast('success', "{$artwork->title} v{$artwork->version}: {$status->label()}.");
    }

    public function destroy(Product $product, ClientArtwork $artwork): RedirectResponse
    {
        $this->authorize('update', $product);
        $this->assertOwn($product, $artwork);

        $this->artworks->remove($artwork);

        return back()->withToast('success', 'Artwork removed.');
    }

    private function assertOwn(Product $product, ClientArtwork $artwork): void
    {
        abort_unless($artwork->client_id === null && $artwork->product_id === $product->id, 404);
    }
}
