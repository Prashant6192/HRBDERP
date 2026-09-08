<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\PackagingMaterial;
use App\Domain\Measurement\Enums\UomDimension;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PackagingMaterial>
 */
class PackagingMaterialFactory extends Factory
{
    protected $model = PackagingMaterial::class;

    public function definition(): array
    {
        return [
            'code' => 'PM-'.fake()->unique()->numberBetween(1000, 9999),
            'name' => fake()->randomElement([
                '200ml HDPE Bottle', '100ml PET Bottle', 'Flip-Top Cap 24mm',
                'Pump Dispenser 28mm', 'Shrink Sleeve Label', 'Mono Carton',
                'Corrugated Shipper Box', 'Aluminium Induction Seal',
            ]).' '.fake()->unique()->numberBetween(1, 999),
            'type' => ItemType::PackagingMaterial,
            'stock_uom_id' => ItemFactory::uom('PCS', UomDimension::Count, '1', 0),
            'hsn_code' => (string) fake()->numberBetween(39000000, 39999999),
            'gst_rate' => '18.000',
            'standard_cost' => (string) fake()->numberBetween(2, 60),
            'is_batch_tracked' => false,
            'requires_qc' => false,
            'reorder_level' => (string) fake()->numberBetween(500, 5000),
            'is_active' => true,
        ];
    }
}
