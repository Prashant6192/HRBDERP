<?php

declare(strict_types=1);

namespace App\Domain\Contract\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Contract\Enums\ArtworkStatus;
use App\Domain\MasterData\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a client's label, carton or bottle artwork, and whether
 * the client has signed it off. Packaging goes ahead on an approved
 * version; the page says so when there is none.
 *
 * @property int $client_id
 * @property int|null $product_id
 * @property ArtworkStatus $status
 */
class ClientArtwork extends Model
{
    use RecordsAuditTrail;

    public const array KINDS = ['label', 'carton', 'bottle', 'other'];

    protected $fillable = [
        'client_id', 'product_id', 'kind', 'title', 'version', 'status',
        'approved_at', 'approved_by_name', 'approved_by_user_id',
        'document_path', 'document_name', 'document_mime', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ArtworkStatus::class,
            'approved_at' => 'date',
        ];
    }

    public function auditLabel(): string
    {
        return "{$this->title} v{$this->version}";
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
