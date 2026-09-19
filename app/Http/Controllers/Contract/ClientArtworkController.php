<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contract;

use App\Domain\Contract\Enums\ArtworkStatus;
use App\Domain\Contract\Models\Client;
use App\Domain\Contract\Models\ClientArtwork;
use App\Domain\Contract\Services\ArtworkService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contract\StoreArtworkRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * A client's artwork versions and their sign-off.
 */
class ClientArtworkController extends Controller
{
    public const string DISK = ArtworkService::DISK;

    public function __construct(private readonly ArtworkService $artworks) {}

    public function store(StoreArtworkRequest $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        $data = $request->validated();
        $artwork = $this->artworks->record($client, isset($data['product_id']) ? (int) $data['product_id'] : null, $data, $request->file('document'), $request->user()->id);

        return back()->withToast('success', "Artwork {$artwork->title} v{$artwork->version} recorded".($artwork->status === ArtworkStatus::Approved ? ' as approved.' : '; awaiting the client\'s approval.'));
    }

    public function status(Request $request, Client $client, ClientArtwork $artwork): RedirectResponse
    {
        $this->authorize('update', $client);
        abort_unless($artwork->client_id === $client->id, 404);

        $data = $request->validate([
            'status' => ['required', Rule::in(array_map(fn (ArtworkStatus $s) => $s->value, ArtworkStatus::cases()))],
            'approved_at' => ['nullable', 'date', 'required_if:status,approved'],
            'approved_by_name' => ['nullable', 'string', 'max:255'],
        ], ['approved_at.required_if' => 'Give the date the client approved it.']);

        $status = ArtworkStatus::from($data['status']);
        $this->artworks->setStatus($artwork, $status, $data['approved_at'] ?? null, $data['approved_by_name'] ?? null, $request->user()->id);

        return back()->withToast('success', "{$artwork->title} v{$artwork->version}: {$status->label()}.");
    }

    public function document(Client $client, ClientArtwork $artwork): HttpResponse
    {
        $this->authorize('view', $client);
        abort_unless($artwork->client_id === $client->id, 404);

        if ($artwork->document_path === null || ! Storage::disk(self::DISK)->exists($artwork->document_path)) {
            abort(404, 'No document is attached to this artwork.');
        }

        return Storage::disk(self::DISK)->response($artwork->document_path, $artwork->document_name, [
            'Content-Type' => $artwork->document_mime ?? 'application/octet-stream',
        ]);
    }

    public function destroy(Client $client, ClientArtwork $artwork): RedirectResponse
    {
        $this->authorize('update', $client);
        abort_unless($artwork->client_id === $client->id, 404);

        $this->artworks->remove($artwork);

        return back()->withToast('success', 'Artwork removed.');
    }
}
