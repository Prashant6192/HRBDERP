<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contract;

use App\Domain\Contract\Enums\ArtworkStatus;
use App\Domain\Contract\Models\Client;
use App\Domain\Contract\Models\ClientArtwork;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contract\StoreArtworkRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * A client's artwork versions and their sign-off.
 */
class ClientArtworkController extends Controller
{
    public const string DISK = 'local';

    public function store(StoreArtworkRequest $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        $data = $request->validated();
        $approved = ($data['status'] ?? 'pending') === 'approved';
        $document = $request->file('document');

        $path = null;

        if ($document !== null) {
            $path = 'clients/artworks/'.$client->id.'/'.Str::uuid().'.'.($document->guessExtension() ?: 'bin');
            Storage::disk(self::DISK)->put($path, (string) file_get_contents($document->getRealPath()));
        }

        $artwork = ClientArtwork::create([
            'client_id' => $client->id,
            'product_id' => $data['product_id'] ?? null,
            'kind' => $data['kind'],
            'title' => $data['title'],
            'version' => $data['version'],
            'status' => $approved ? ArtworkStatus::Approved : ArtworkStatus::Pending,
            'approved_at' => $approved ? $data['approved_at'] : null,
            'approved_by_name' => $approved ? ($data['approved_by_name'] ?? null) : null,
            'approved_by_user_id' => $approved ? $request->user()->id : null,
            'document_path' => $path,
            'document_name' => $document?->getClientOriginalName(),
            'document_mime' => $document?->getMimeType(),
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        if ($approved) {
            $this->supersedeOthers($artwork);
        }

        return back()->withToast('success', "Artwork {$artwork->title} v{$artwork->version} recorded".($approved ? ' as approved.' : '; awaiting the client\'s approval.'));
    }

    /**
     * Approve, reject or supersede a version. Approving one supersedes the
     * other approved version of the same kind for the same product.
     */
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

        $artwork->fill([
            'status' => $status,
            'approved_at' => $status === ArtworkStatus::Approved ? $data['approved_at'] : $artwork->approved_at,
            'approved_by_name' => $status === ArtworkStatus::Approved ? ($data['approved_by_name'] ?? $artwork->approved_by_name) : $artwork->approved_by_name,
            'approved_by_user_id' => $status === ArtworkStatus::Approved ? $request->user()->id : $artwork->approved_by_user_id,
        ])->save();

        if ($status === ArtworkStatus::Approved) {
            $this->supersedeOthers($artwork);
        }

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

        if ($artwork->document_path !== null) {
            Storage::disk(self::DISK)->delete($artwork->document_path);
        }

        $artwork->delete();

        return back()->withToast('success', 'Artwork removed.');
    }

    private function supersedeOthers(ClientArtwork $artwork): void
    {
        ClientArtwork::query()
            ->where('client_id', $artwork->client_id)
            ->where('kind', $artwork->kind)
            ->where('status', ArtworkStatus::Approved->value)
            ->where('id', '!=', $artwork->id)
            ->when($artwork->product_id !== null, fn ($q) => $q->where('product_id', $artwork->product_id), fn ($q) => $q->whereNull('product_id'))
            ->update(['status' => ArtworkStatus::Superseded->value]);
    }
}
