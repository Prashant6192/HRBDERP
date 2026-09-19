<?php

declare(strict_types=1);

namespace App\Domain\Contract\Services;

use App\Domain\Contract\Enums\ArtworkStatus;
use App\Domain\Contract\Models\Client;
use App\Domain\Contract\Models\ClientArtwork;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Artwork on file: the client's for a third-party job, the company's own
 * for its brands. Either way the packing line works from the approved
 * version, and a batch page shows what the pack must look like.
 */
class ArtworkService
{
    public const string DISK = 'local';

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(?Client $client, ?int $productId, array $data, ?UploadedFile $document, int $userId): ClientArtwork
    {
        $approved = ($data['status'] ?? 'pending') === 'approved';
        $path = null;

        if ($document !== null) {
            $folder = $client === null ? 'clients/artworks/own/'.($productId ?? 'all') : 'clients/artworks/'.$client->id;
            $path = $folder.'/'.Str::uuid().'.'.($document->guessExtension() ?: 'bin');
            Storage::disk(self::DISK)->put($path, (string) file_get_contents($document->getRealPath()));
        }

        $artwork = ClientArtwork::create([
            'client_id' => $client?->id,
            'product_id' => $productId,
            'kind' => $data['kind'],
            'title' => $data['title'],
            'version' => $data['version'],
            'status' => $approved ? ArtworkStatus::Approved : ArtworkStatus::Pending,
            'approved_at' => $approved ? $data['approved_at'] : null,
            'approved_by_name' => $approved ? ($data['approved_by_name'] ?? null) : null,
            'approved_by_user_id' => $approved ? $userId : null,
            'document_path' => $path,
            'document_name' => $document?->getClientOriginalName(),
            'document_mime' => $document?->getMimeType(),
            'notes' => $data['notes'] ?? null,
            'created_by' => $userId,
        ]);

        if ($approved) {
            $this->supersedeOthers($artwork);
        }

        return $artwork;
    }

    /**
     * Approve, reject or supersede a version. Approving one supersedes the
     * other approved version of the same kind for the same product.
     */
    public function setStatus(ClientArtwork $artwork, ArtworkStatus $status, ?string $approvedAt, ?string $approvedByName, int $userId): ClientArtwork
    {
        $artwork->fill([
            'status' => $status,
            'approved_at' => $status === ArtworkStatus::Approved ? $approvedAt : $artwork->approved_at,
            'approved_by_name' => $status === ArtworkStatus::Approved ? ($approvedByName ?? $artwork->approved_by_name) : $artwork->approved_by_name,
            'approved_by_user_id' => $status === ArtworkStatus::Approved ? $userId : $artwork->approved_by_user_id,
        ])->save();

        if ($status === ArtworkStatus::Approved) {
            $this->supersedeOthers($artwork);
        }

        return $artwork;
    }

    public function remove(ClientArtwork $artwork): void
    {
        if ($artwork->document_path !== null) {
            Storage::disk(self::DISK)->delete($artwork->document_path);
        }

        $artwork->delete();
    }

    /**
     * What the packing line must match for a batch: the approved artwork
     * for the product (the client's on a third-party job, the company's
     * own otherwise), plus what is still awaiting approval, flagged.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forBatch(ManufacturingOrder $order): array
    {
        if ($order->product_id === null) {
            return [];
        }

        return $this->forProduct($order->product_id, $order->client_id)
            ->filter(fn (ClientArtwork $a) => in_array($a->status, [ArtworkStatus::Approved, ArtworkStatus::Pending], true))
            ->map(fn (ClientArtwork $a) => $this->serialize($a))
            ->values()
            ->all();
    }

    /**
     * Every version on file for a product. With a client, the client's
     * versions for the product and for all of their products; without one,
     * the company's own.
     *
     * @return Collection<int, ClientArtwork>
     */
    public function forProduct(int $productId, ?int $clientId): Collection
    {
        return ClientArtwork::query()
            ->with(['product:id,name', 'client:id,code,name', 'approvedByUser:id,name'])
            ->where(function ($q) use ($productId, $clientId): void {
                if ($clientId === null) {
                    $q->whereNull('client_id')->where('product_id', $productId);

                    return;
                }

                $q->where('client_id', $clientId)->where(fn ($p) => $p->where('product_id', $productId)->orWhereNull('product_id'));
            })
            ->orderByRaw("CASE status WHEN 'approved' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(ClientArtwork $a): array
    {
        $isImage = $a->document_mime !== null && str_starts_with($a->document_mime, 'image/');

        return [
            'id' => $a->id,
            'client_id' => $a->client_id,
            'client' => $a->client?->name,
            'product_id' => $a->product_id,
            'product' => $a->product?->name,
            'kind' => $a->kind,
            'title' => $a->title,
            'version' => $a->version,
            'status' => $a->status->value,
            'status_label' => $a->status->label(),
            'approved_at' => $a->approved_at?->toDateString(),
            'approved_by_name' => $a->approved_by_name,
            'recorded_by' => $a->approvedByUser?->name,
            'document' => $a->document_path === null ? null : [
                'name' => $a->document_name,
                'url' => route('artworks.document', $a),
                'is_image' => $isImage,
            ],
            'notes' => $a->notes,
        ];
    }

    private function supersedeOthers(ClientArtwork $artwork): void
    {
        ClientArtwork::query()
            ->when($artwork->client_id !== null, fn ($q) => $q->where('client_id', $artwork->client_id), fn ($q) => $q->whereNull('client_id'))
            ->where('kind', $artwork->kind)
            ->where('status', ArtworkStatus::Approved->value)
            ->where('id', '!=', $artwork->id)
            ->when($artwork->product_id !== null, fn ($q) => $q->where('product_id', $artwork->product_id), fn ($q) => $q->whereNull('product_id'))
            ->update(['status' => ArtworkStatus::Superseded->value]);
    }
}
