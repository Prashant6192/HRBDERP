<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Domain\Contract\Models\Client;
use App\Domain\Documents\Enums\DocumentKind;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Services\DocumentService;
use App\Domain\MasterData\Models\Item;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Controlled documents with version history and approval status.
 */
class DocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Document::class);

        $kind = in_array($request->string('kind')->toString(), array_column(DocumentKind::cases(), 'value'), true) ? $request->string('kind')->toString() : null;
        $search = $request->string('search')->toString();

        $documents = Document::query()
            ->with(['item:id,code,name', 'client:id,code,name', 'createdBy:id,name', 'approvedBy:id,name'])
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->search($search)
            ->orderBy('code')
            ->orderByDesc('version')
            ->get();

        $groups = $documents->groupBy('code')->map(fn ($versions, string $code) => [
            'code' => $code,
            'title' => $versions->first()->title,
            'kind' => $versions->first()->kind->value,
            'kind_label' => $versions->first()->kind->label(),
            'item' => $versions->first()->item?->name,
            'client' => $versions->first()->client?->name,
            'current_version' => $versions->firstWhere('status', DocumentStatus::Approved)?->version,
            'versions' => $versions->map(fn (Document $d) => [
                'id' => $d->id,
                'version' => $d->version,
                'title' => $d->title,
                'status' => $d->status->value,
                'status_label' => $d->status->label(),
                'change_summary' => $d->change_summary,
                'effective_from' => $d->effective_from?->toDateString(),
                'created_by' => $d->createdBy?->name,
                'created_at' => $d->created_at?->toDateString(),
                'approved_by' => $d->approvedBy?->name,
                'approved_at' => $d->approved_at?->toDateString(),
                'file_name' => $d->file_name,
                'download' => $d->file_path ? route('documents.download', $d) : null,
                'can_approve' => $d->status->value === 'draft' && $request->user()->can('approve', $d) && (int) $d->created_by !== (int) $request->user()->id,
                'is_author' => (int) $d->created_by === (int) $request->user()->id,
                'can_withdraw' => in_array($d->status->value, ['draft', 'approved'], true) && $request->user()->can('withdraw', $d),
            ])->values()->all(),
        ])->values()->all();

        return Inertia::render('documents/index', [
            'groups' => $groups,
            'kinds' => DocumentKind::options(),
            'filters' => ['kind' => $kind, 'search' => $search],
            'items' => Item::query()->active()->orderBy('name')->get(['id', 'code', 'name', 'type'])->map(fn (Item $i) => ['value' => $i->id, 'label' => "{$i->code} — {$i->name}"])->all(),
            'clients' => Client::query()->active()->orderBy('name')->get(['id', 'code', 'name'])->map(fn (Client $c) => ['value' => $c->id, 'label' => $c->name])->all(),
            'can' => ['create' => $request->user()->can('create', Document::class)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Document::class);

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-\/]+$/'],
            'kind' => ['required', Rule::enum(DocumentKind::class)],
            'title' => ['required', 'string', 'max:255'],
            'item_id' => ['nullable', 'integer', Rule::exists('items', 'id')],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')],
            'change_summary' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'effective_from' => ['nullable', 'date'],
            'file' => ['nullable', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx'],
        ]);

        try {
            $document = $this->documents->newVersion($data, $request->file('file'), $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return back()->withToast('success', "{$document->code} v{$document->version} saved as a draft; someone else must approve it before production references it.");
    }

    public function approve(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('approve', $document);

        try {
            $this->documents->approve($document, $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return back()->withToast('success', "{$document->code} v{$document->version} is now the current version.");
    }

    public function withdraw(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('withdraw', $document);

        try {
            $this->documents->withdraw($document, $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return back()->withToast('success', "{$document->code} v{$document->version} withdrawn.");
    }

    public function download(Request $request, Document $document): HttpResponse
    {
        $this->authorize('view', $document);

        if ($document->file_path === null || ! Storage::disk(DocumentService::DISK)->exists($document->file_path)) {
            abort(404);
        }

        return Storage::disk(DocumentService::DISK)->response($document->file_path, $document->file_name, ['Content-Type' => $document->file_mime ?? 'application/octet-stream']);
    }
}
