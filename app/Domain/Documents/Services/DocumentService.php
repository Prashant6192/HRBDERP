<?php

declare(strict_types=1);

namespace App\Domain\Documents\Services;

use App\Domain\Documents\Enums\DocumentKind;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Document version control.
 *
 * A new version is a draft. Approving it — by someone other than its
 * author — makes it the current version and supersedes the one before.
 * A superseded or withdrawn version stays on file, unchanged; nothing is
 * ever edited in place.
 */
class DocumentService
{
    public const DISK = 'local';

    /**
     * @param  array{code?: string|null, kind: string, title: string, item_id?: int|null, client_id?: int|null, change_summary?: string|null, notes?: string|null, effective_from?: string|null}  $data
     */
    public function newVersion(array $data, ?UploadedFile $file, int $userId): Document
    {
        return DB::transaction(function () use ($data, $file, $userId): Document {
            $kind = DocumentKind::from($data['kind']);
            $code = strtoupper(trim((string) ($data['code'] ?? '')));

            if ($code === '') {
                $code = $this->nextCode($kind);
            }

            $latest = Document::query()->where('code', $code)->lockForUpdate()->orderByDesc('version')->first();

            if ($latest !== null && $latest->status === DocumentStatus::Draft) {
                throw new InvalidArgumentException("{$code} already has a draft (v{$latest->version}); approve or withdraw it before opening another.");
            }

            $stored = null;

            if ($file !== null) {
                $path = 'documents/'.$code.'/v'.(($latest?->version ?? 0) + 1).'-'.now()->format('YmdHis').'.'.($file->getClientOriginalExtension() ?: 'bin');
                Storage::disk(self::DISK)->put($path, (string) file_get_contents($file->getRealPath()));
                $stored = ['path' => $path, 'name' => $file->getClientOriginalName(), 'mime' => $file->getClientMimeType()];
            }

            return Document::query()->create([
                'code' => $code,
                'version' => ($latest?->version ?? 0) + 1,
                'kind' => $kind,
                'title' => trim($data['title']),
                'status' => DocumentStatus::Draft,
                'item_id' => $data['item_id'] ?? $latest?->item_id,
                'client_id' => $data['client_id'] ?? $latest?->client_id,
                'file_path' => $stored['path'] ?? null,
                'file_name' => $stored['name'] ?? null,
                'file_mime' => $stored['mime'] ?? null,
                'change_summary' => $data['change_summary'] ?? null,
                'notes' => $data['notes'] ?? null,
                'effective_from' => $data['effective_from'] ?? null,
                'supersedes_id' => $latest?->id,
                'created_by' => $userId,
            ]);
        });
    }

    /**
     * Make a draft the current version. Maker and checker must differ.
     */
    public function approve(Document $document, int $userId): Document
    {
        return DB::transaction(function () use ($document, $userId): Document {
            $document = Document::query()->lockForUpdate()->findOrFail($document->getKey());

            if ($document->status !== DocumentStatus::Draft) {
                throw new InvalidArgumentException("{$document->code} v{$document->version} is {$document->status->label()}; only a draft can be approved.");
            }

            if ((int) $document->created_by === $userId) {
                throw new InvalidArgumentException('The author of a version cannot approve it. Maker and checker must differ.');
            }

            Document::query()
                ->where('code', $document->code)
                ->where('status', DocumentStatus::Approved->value)
                ->whereKeyNot($document->id)
                ->update(['status' => DocumentStatus::Superseded->value]);

            $document->fill([
                'status' => DocumentStatus::Approved,
                'approved_by' => $userId,
                'approved_at' => now(),
                'effective_from' => $document->effective_from ?? now()->toDateString(),
            ])->save();

            return $document->refresh();
        });
    }

    public function withdraw(Document $document, int $userId): Document
    {
        return DB::transaction(function () use ($document, $userId): Document {
            $document = Document::query()->lockForUpdate()->findOrFail($document->getKey());

            if (in_array($document->status, [DocumentStatus::Withdrawn, DocumentStatus::Superseded], true)) {
                throw new InvalidArgumentException("{$document->code} v{$document->version} is already {$document->status->label()}.");
            }

            $document->fill(['status' => DocumentStatus::Withdrawn, 'withdrawn_by' => $userId, 'withdrawn_at' => now()])->save();

            return $document->refresh();
        });
    }

    /**
     * The approved, current documents production should reference for a
     * product (and, for a client's product, the client's own).
     *
     * @return list<array<string, mixed>>
     */
    public function currentFor(?int $itemId, ?int $clientId = null): array
    {
        if ($itemId === null && $clientId === null) {
            return [];
        }

        return Document::query()
            ->current()
            ->where(fn ($q) => $q
                ->when($itemId !== null, fn ($q) => $q->where('item_id', $itemId))
                ->when($clientId !== null, fn ($q) => $q->orWhere(fn ($q) => $q->where('client_id', $clientId)->whereNull('item_id'))))
            ->orderBy('kind')
            ->orderBy('code')
            ->get()
            ->map(fn (Document $d) => [
                'id' => $d->id,
                'code' => $d->code,
                'version' => $d->version,
                'kind' => $d->kind->value,
                'kind_label' => $d->kind->label(),
                'title' => $d->title,
                'effective_from' => $d->effective_from?->toDateString(),
                'has_file' => $d->file_path !== null,
            ])
            ->all();
    }

    private function nextCode(DocumentKind $kind): string
    {
        $prefix = match ($kind) {
            DocumentKind::Sop => 'SOP',
            DocumentKind::Specification => 'SPEC',
            DocumentKind::Artwork => 'ART',
            DocumentKind::Coa => 'COA',
            DocumentKind::Formula => 'FRM',
            DocumentKind::QcStandard => 'QCS',
            DocumentKind::Other => 'DOC',
        };

        $count = Document::query()->where('code', 'like', "{$prefix}-%")->distinct('code')->count('code');

        do {
            $count++;
            $code = sprintf('%s-%04d', $prefix, $count);
        } while (Document::query()->where('code', $code)->exists());

        return $code;
    }
}
