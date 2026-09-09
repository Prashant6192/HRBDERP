<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Formulation\Models\FormulaIngredient;
use App\Domain\Formulation\Models\FormulaVersion;
use App\Domain\MasterData\Models\RawMaterial;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormulaIngredient>
 */
class FormulaIngredientFactory extends Factory
{
    protected $model = FormulaIngredient::class;

    public function definition(): array
    {
        return [
            'formula_version_id' => FormulaVersion::factory(),
            'line_no' => fake()->unique()->numberBetween(1, 500),
            'item_id' => RawMaterial::factory(),
            'percentage' => (string) fake()->randomFloat(3, 0.1, 20),
            'is_qs' => false,
            'grade' => fake()->randomElement(['IH', 'IP']),
            'purpose' => fake()->randomElement(['Cleaning', 'Humectant', 'Preservative', 'Emollient']),
        ];
    }

    public function qs(): static
    {
        return $this->state(['percentage' => null, 'is_qs' => true, 'qs_note' => 'QS to 100']);
    }
}
