<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use Database\Factories\VendorFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $gstin
 */
class Vendor extends Model
{
    /** @use HasFactory<VendorFactory> */
    use HasFactory;

    use RecordsAuditTrail, SoftDeletes;

    protected $fillable = [
        'code', 'name', 'legal_name', 'gstin', 'pan',
        'contact_person', 'email', 'phone',
        'address_line_1', 'address_line_2', 'city', 'state', 'pincode', 'country',
        'payment_terms_days', 'credit_limit', 'supply_type',
        'is_approved', 'is_active', 'notes',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_approved' => 'boolean',
            'is_active' => 'boolean',
            'payment_terms_days' => 'integer',
        ];
    }

    public function auditLabel(): string
    {
        return "{$this->code} — {$this->name}";
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
     * Vendors a purchase order may actually be raised against.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePurchasable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_approved', true);
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
                ->orWhere('city', 'ilike', "%{$term}%");
        });
    }
}
