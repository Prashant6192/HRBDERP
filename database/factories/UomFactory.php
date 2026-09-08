<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Measurement\Enums\UomDimension;
use App\Domain\Measurement\Models\Uom;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Uom>
 */
class UomFactory extends Factory
{
    protected $model = Uom::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('U??')),
            'name' => fake()->word(),
            'dimension' => UomDimension::Mass,
            'is_base' => false,
            'factor_to_base' => '1',
            'display_scale' => 3,
            'is_active' => true,
        ];
    }

    /**
     * The canonical unit of a dimension: factor 1, and the pivot every other
     * unit of that dimension converts through.
     */
    public function base(UomDimension $dimension): static
    {
        return $this->state(fn () => [
            'code' => $dimension->baseUnitCode(),
            'dimension' => $dimension,
            'is_base' => true,
            'factor_to_base' => '1',
        ]);
    }

    public function ofDimension(UomDimension $dimension): static
    {
        return $this->state(fn () => ['dimension' => $dimension]);
    }

    public function withFactor(string $factor): static
    {
        return $this->state(fn () => ['factor_to_base' => $factor]);
    }
}
