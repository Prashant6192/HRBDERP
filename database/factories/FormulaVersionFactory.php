<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Formulation\Enums\FormulaVersionStatus;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Formulation\Models\FormulaVersion;
use App\Domain\Measurement\Enums\UomDimension;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormulaVersion>
 */
class FormulaVersionFactory extends Factory
{
    protected $model = FormulaVersion::class;

    public function definition(): array
    {
        return [
            'formula_id' => Formula::factory(),
            'version_number' => 1,
            'status' => FormulaVersionStatus::Draft,
            'batch_size' => '100',
            'batch_uom_id' => ItemFactory::uom('G', UomDimension::Mass, '1', 3),
            'total_percentage' => '0',
            'source' => 'manual',
        ];
    }

    public function active(): static
    {
        return $this->state([
            'status' => FormulaVersionStatus::Active,
            'activated_at' => now(),
            'approved_at' => now(),
        ]);
    }
}
