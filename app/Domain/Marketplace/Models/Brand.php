<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Contract\Models\Client;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A brand sold online: Rahat Rooh, Cleanse Ayurveda.
 *
 * The brand decides whose batches its parcels may take — the company's own
 * or one contract client's — and the store they leave from unless an upload
 * says otherwise. An outside agency is given the brands it may upload for.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $gstin
 * @property int|null $client_id
 * @property int|null $default_warehouse_id
 * @property bool $is_active
 */
class Brand extends Model
{
    use RecordsAuditTrail;

    protected $fillable = [
        'code', 'name', 'legal_name', 'gstin', 'client_id', 'default_warehouse_id', 'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function defaultWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }

    /**
     * The people outside the company who may upload labels for this brand.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * @return HasMany<MarketplaceListing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(MarketplaceListing::class);
    }
}
