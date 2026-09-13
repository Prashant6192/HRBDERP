<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contract;

use App\Domain\Contract\Models\Client;
use App\Domain\Contract\Models\ClientQcSpec;
use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\Item;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contract\StoreQcSpecRequest;
use Illuminate\Http\RedirectResponse;

/**
 * What a client wants checked on each of their products.
 */
class ClientQcSpecController extends Controller
{
    public function upsert(StoreQcSpecRequest $request, Client $client, Item $product): RedirectResponse
    {
        $this->authorize('update', $client);
        abort_unless($product->type === ItemType::FinishedGood, 404);

        $parameters = array_values(array_map(fn (array $p) => [
            'name' => trim((string) $p['name']),
            'min' => isset($p['min']) && $p['min'] !== '' ? (string) $p['min'] : null,
            'max' => isset($p['max']) && $p['max'] !== '' ? (string) $p['max'] : null,
            'target' => isset($p['target']) && $p['target'] !== '' ? (string) $p['target'] : null,
            'unit' => isset($p['unit']) && $p['unit'] !== '' ? (string) $p['unit'] : null,
        ], $request->validated('parameters')));

        ClientQcSpec::query()->updateOrCreate(
            ['client_id' => $client->id, 'product_id' => $product->id],
            ['parameters' => $parameters, 'notes' => $request->validated('notes'), 'updated_by' => $request->user()->id, 'created_by' => $request->user()->id],
        );

        return back()->withToast('success', "QC specification for {$product->name} saved for {$client->name}.");
    }

    public function destroy(Client $client, ClientQcSpec $spec): RedirectResponse
    {
        $this->authorize('update', $client);
        abort_unless($spec->client_id === $client->id, 404);

        $spec->delete();

        return back()->withToast('success', 'QC specification removed.');
    }
}
