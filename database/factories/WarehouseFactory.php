<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    public function definition(): array
    {
        return [
            'code' => 'WH-'.strtoupper(fake()->unique()->bothify('??##')),
            'name' => fake()->company().' Store',
            'type' => WarehouseType::General,
            'address_line_1' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => 'Maharashtra',
            'pincode' => fake()->numerify('4#####'),
            'country' => 'India',
            'is_quarantine' => false,
            'is_active' => true,
        ];
    }

    public function ofType(WarehouseType $type): static
    {
        return $this->state(fn () => [
            'type' => $type,
            'is_quarantine' => $type->holdsQuarantinedStock(),
        ]);
    }

    public function quarantine(): static
    {
        return $this->ofType(WarehouseType::Quarantine);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
