<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\Product;
use App\Domain\Measurement\Enums\UomDimension;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $netContent = fake()->randomElement(['100', '200', '250', '500']);

        return [
            'code' => 'FG-'.fake()->unique()->numberBetween(1000, 9999),
            'name' => fake()->randomElement([
                'Hydra Smooth Shampoo', 'Argan Repair Conditioner',
                'Vitamin C Face Serum', 'Aloe Soothing Gel',
                'Charcoal Face Wash', 'Shea Body Butter',
            ]).' '.$netContent.'ml',
            'type' => ItemType::FinishedGood,
            'brand' => fake()->randomElement(['HRBD', 'HRBD Professional', 'HRBD Naturals']),
            'stock_uom_id' => ItemFactory::uom('PCS', UomDimension::Count, '1', 0),
            'net_content' => $netContent,
            'net_content_uom_id' => ItemFactory::uom('ML', UomDimension::Volume, '1', 2),
            'mrp' => (string) fake()->numberBetween(199, 1499),
            'hsn_code' => '33051010',
            'gst_rate' => '18.000',
            'barcode' => fake()->unique()->ean13(),
            'is_batch_tracked' => true,
            'requires_qc' => true,
            'shelf_life_days' => 1095,
            'is_active' => true,
        ];
    }
}
