<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contract;

use App\Domain\Contract\Models\ClientArtwork;
use App\Domain\Contract\Services\ArtworkService;
use App\Domain\MasterData\Models\Product;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The artwork file itself, for anyone who may see the product, the
 * client or the production floor: the packing line opens it from the
 * batch, the management dashboard from the phone.
 */
class ArtworkDocumentController extends Controller
{
    public function __invoke(Request $request, ClientArtwork $artwork): HttpResponse
    {
        $user = $request->user();

        $allowed = $user->can('production.view')
            || $user->can('production.consume')
            || $user->can('viewAny', Product::class)
            || ($artwork->client_id !== null && $artwork->client !== null && $user->can('view', $artwork->client));

        abort_unless($allowed, 403);

        if ($artwork->document_path === null || ! Storage::disk(ArtworkService::DISK)->exists($artwork->document_path)) {
            abort(404, 'No file is attached to this artwork.');
        }

        return Storage::disk(ArtworkService::DISK)->response($artwork->document_path, $artwork->document_name, [
            'Content-Type' => $artwork->document_mime ?? 'application/octet-stream',
        ]);
    }
}
