<?php

declare(strict_types=1);

namespace App\Domain\Documents\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Contract\Models\Client;
use App\Domain\Documents\Enums\DocumentKind;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\MasterData\Models\Item;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a controlled document: an SOP, a specification, an
 * artwork, a certificate of analysis, a QC standard. Versions of the same
 * document share a code; exactly one of them is approved at a time, and
 * that is the one production references.
 *
 * @property int $id
 * @property string $code
 * @property int $version
 * @property DocumentKind $kind
 * @property string $title
 * @property DocumentStatus $status
 * @property int|null $item_id
 * @property int|null $client_id
 * @property string|null $file_path
 * @property string|null $file_name
 * @property string|null $file_mime
 * @property string|null $change_summary
 * @property string|null $notes
 * @property CarbonImmutable|null $effective_from
 * @property int|null $supersedes_id
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 */
class Document extends Model
{
    use RecordsAuditTrail;

    protected $fillable = [
        'code', 'version', 'kind', 'title', 'status', 'item_id', 'client_id',
        'file_path', 'file_name', 'file_mime', 'change_summary', 'notes', 'effective_from',
        'supersedes_id', 'created_by', 'approved_by', 'approved_at', 'withdrawn_by', 'withdrawn_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'kind' => DocumentKind::class,
            'status' => DocumentStatus::class,
            'effective_from' => 'immutable_date',
            'approved_at' => 'immutable_datetime',
            'withdrawn_at' => 'immutable_datetime',
        ];
    }

    public function auditLabel(): string
    {
        return "{$this->code} v{$this->version} — {$this->title}";
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'supersedes_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('status', DocumentStatus::Approved->value);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('code', 'ilike', "%{$term}%")
            ->orWhere('title', 'ilike', "%{$term}%"));
    }
}
