<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Formulation\Enums\FormulaStatus;
use App\Domain\Formulation\Models\Formula;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Formula>
 */
class FormulaFactory extends Factory
{
    protected $model = Formula::class;

    public function definition(): array
    {
        return [
            'code' => 'FRM-'.fake()->unique()->numberBetween(1000, 9999),
            'name' => fake()->randomElement(['Hydrating', 'Brightening', 'Oil Control', 'Lavender Silk']).' '.fake()->randomElement(['Face Wash', 'Body Lotion', 'Shampoo']),
            'status' => FormulaStatus::Draft,
        ];
    }

    public function archived(): static
    {
        return $this->state(['status' => FormulaStatus::Archived]);
    }
}
