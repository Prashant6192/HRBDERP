<?php

declare(strict_types=1);

namespace App\Domain\Contract\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\MasterData\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a client wants checked on their product, and within what limits.
 * The QC Checkpoint offers these as the checklist for that client's
 * batches of that product.
 *
 * @property int $client_id
 * @property int $product_id
 * @property list<array{name: string, min: string|null, max: string|null, target: string|null, unit: string|null}> $parameters
 */
class ClientQcSpec extends Model
{
    use RecordsAuditTrail;

    protected $fillable = ['client_id', 'product_id', 'parameters', 'notes', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
        ];
    }

    public function auditLabel(): string
    {
        return "QC spec for client #{$this->client_id}, product #{$this->product_id}";
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'product_id');
    }
}
