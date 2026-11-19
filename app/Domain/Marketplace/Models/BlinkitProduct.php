<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use Illuminate\Database\Eloquent\Model;

/**
 * A product sold on Blinkit, as its labels print it.
 *
 * @property int $id
 * @property string $brand_name
 * @property string $product_name
 * @property string $upc
 * @property string|null $pid
 * @property string $mrp
 * @property string|null $uom
 * @property int|null $units_in_box
 * @property bool $is_active
 */
class BlinkitProduct extends Model
{
    use RecordsAuditTrail;

    protected $fillable = [
        'brand_name', 'product_name', 'upc', 'pid', 'mrp', 'uom', 'units_in_box', 'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'units_in_box' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function auditLabel(): string
    {
        return "{$this->product_name} ({$this->upc})";
    }
}
