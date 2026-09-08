<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Enums\UomDimension;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RawMaterial>
 */
class RawMaterialFactory extends Factory
{
    protected $model = RawMaterial::class;

    public function definition(): array
    {
        return [
            'code' => 'RM-'.fake()->unique()->numberBetween(1000, 9999),
            'name' => fake()->randomElement([
                'Sodium Laureth Sulphate', 'Cocamidopropyl Betaine', 'Glycerine',
                'Aloe Vera Extract', 'Citric Acid', 'Vitamin E Acetate',
                'Guar Hydroxypropyltrimonium Chloride', 'Phenoxyethanol',
                'Argan Oil', 'Coconut Oil', 'Shea Butter', 'Hyaluronic Acid',
            ]).' '.fake()->unique()->numberBetween(1, 999),
            'type' => ItemType::RawMaterial,
            'stock_uom_id' => ItemFactory::uom('KG', UomDimension::Mass, '1000', 3),
            'hsn_code' => (string) fake()->numberBetween(34000000, 34999999),
            'gst_rate' => '18.000',
            'standard_cost' => (string) fake()->numberBetween(80, 4000),
            'is_batch_tracked' => true,
            'requires_qc' => true,
            'shelf_life_days' => fake()->randomElement([365, 540, 730]),
            'reorder_level' => (string) fake()->numberBetween(20, 200),
            'is_active' => true,
        ];
    }
}
