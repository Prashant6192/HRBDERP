<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Contract\Models\Client;
use App\Domain\Dispatch\Enums\CustomerKind;
use App\Domain\Dispatch\Support\GstStateCodes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Who finished goods are billed to and sent to: a contract client taking
 * delivery of their own goods, a marketplace, a distributor.
 *
 * A customer linked to a contract client is the only one that client's
 * batches may be dispatched to; the company's own goods may go to anyone.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $gstin
 * @property CustomerKind $kind
 * @property int|null $client_id
 * @property bool $is_active
 */
class Customer extends Model
{
    use RecordsAuditTrail, SoftDeletes;

    protected $fillable = [
        'code', 'name', 'legal_name', 'gstin', 'pan', 'kind', 'client_id',
        'contact_person', 'phone', 'email',
        'billing_address_line_1', 'billing_address_line_2', 'billing_city', 'billing_state', 'billing_pincode',
        'shipping_address_line_1', 'shipping_address_line_2', 'shipping_city', 'shipping_state', 'shipping_pincode',
        'notes', 'is_active', 'created_by', 'updated_by',
    ];

    /** @var list<string> */
    protected array $auditExclude = ['updated_by'];

    protected function casts(): array
    {
        return [
            'kind' => CustomerKind::class,
            'is_active' => 'boolean',
        ];
    }

    public function auditLabel(): string
    {
        return "{$this->code} {$this->name}";
    }

    /**
     * The next customer code: CUS-001, CUS-002, …
     */
    public static function nextCode(): string
    {
        $n = (int) static::withTrashed()->count();

        do {
            $code = sprintf('CUS-%03d', ++$n);
        } while (static::withTrashed()->where('code', $code)->exists());

        return $code;
    }

    /** The GST state the customer is registered in, off their GSTIN. */
    public function stateCode(): ?string
    {
        return GstStateCodes::fromGstin($this->gstin);
    }

    public function isRegistered(): bool
    {
        return $this->gstin !== null && $this->gstin !== '';
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<Dispatch, $this>
     */
    public function dispatches(): HasMany
    {
        return $this->hasMany(Dispatch::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $query) use ($term): void {
            $query->where('code', 'ilike', "%{$term}%")
                ->orWhere('name', 'ilike', "%{$term}%")
                ->orWhere('legal_name', 'ilike', "%{$term}%")
                ->orWhere('gstin', 'ilike', "%{$term}%")
                ->orWhere('billing_city', 'ilike', "%{$term}%");
        });
    }
}
