<?php

declare(strict_types=1);

namespace App\Domain\Contract\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\Item;
use App\Domain\Planning\Models\ProductionPlan;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A third party the company manufactures for. Their products, formulas,
 * material and finished goods are tagged with them throughout the normal
 * workflow; nothing of theirs is ever mistaken for the company's own.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $gstin
 * @property bool $is_active
 */
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    use RecordsAuditTrail, SoftDeletes;

    protected $fillable = [
        'code', 'name', 'legal_name', 'gstin', 'pan',
        'contact_person', 'phone', 'email',
        'billing_address_line_1', 'billing_address_line_2', 'billing_city', 'billing_state', 'billing_pincode',
        'shipping_address_line_1', 'shipping_address_line_2', 'shipping_city', 'shipping_state', 'shipping_pincode',
        'payment_terms_days', 'credit_limit', 'agreement_ref', 'agreement_expires_at',
        'notes', 'is_active', 'created_by', 'updated_by',
    ];

    /** @var list<string> */
    protected array $auditExclude = ['updated_by'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'payment_terms_days' => 'integer',
            'agreement_expires_at' => 'date',
        ];
    }

    public function auditLabel(): string
    {
        return "{$this->code} {$this->name}";
    }

    /**
     * The next client code: TP-001, TP-002, …
     */
    public static function nextCode(): string
    {
        $n = (int) static::withTrashed()->count();

        do {
            $code = sprintf('TP-%03d', ++$n);
        } while (static::withTrashed()->where('code', $code)->exists());

        return $code;
    }

    /**
     * Products made for this client.
     *
     * @return HasMany<Item, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Item::class, 'client_id')->where('type', ItemType::FinishedGood->value);
    }

    /**
     * @return HasMany<Formula, $this>
     */
    public function formulas(): HasMany
    {
        return $this->hasMany(Formula::class, 'client_id');
    }

    /**
     * @return HasMany<ProductionPlan, $this>
     */
    public function plans(): HasMany
    {
        return $this->hasMany(ProductionPlan::class, 'client_id');
    }

    /**
     * @return HasMany<ManufacturingOrder, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(ManufacturingOrder::class, 'client_id');
    }

    /**
     * Batches the client owns: material they supplied and finished goods
     * made for them.
     *
     * @return HasMany<InventoryLot, $this>
     */
    public function lots(): HasMany
    {
        return $this->hasMany(InventoryLot::class, 'owner_client_id');
    }

    /**
     * @return HasMany<ClientArtwork, $this>
     */
    public function artworks(): HasMany
    {
        return $this->hasMany(ClientArtwork::class, 'client_id')->orderByDesc('id');
    }

    /**
     * @return HasMany<ClientQcSpec, $this>
     */
    public function qcSpecs(): HasMany
    {
        return $this->hasMany(ClientQcSpec::class, 'client_id');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
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

        return $query->where(function (Builder $query) use ($term): void {
            $query->where('code', 'ilike', "%{$term}%")
                ->orWhere('name', 'ilike', "%{$term}%")
                ->orWhere('legal_name', 'ilike', "%{$term}%")
                ->orWhere('gstin', 'ilike', "%{$term}%")
                ->orWhere('contact_person', 'ilike', "%{$term}%");
        });
    }

    public function billingAddress(): string
    {
        return implode(', ', array_filter([
            $this->billing_address_line_1, $this->billing_address_line_2, $this->billing_city, $this->billing_state, $this->billing_pincode,
        ]));
    }

    public function shippingAddress(): string
    {
        $address = implode(', ', array_filter([
            $this->shipping_address_line_1, $this->shipping_address_line_2, $this->shipping_city, $this->shipping_state, $this->shipping_pincode,
        ]));

        return $address !== '' ? $address : $this->billingAddress();
    }
}
