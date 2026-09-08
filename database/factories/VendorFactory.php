<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Procurement\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vendor>
 */
class VendorFactory extends Factory
{
    protected $model = Vendor::class;

    public function definition(): array
    {
        return [
            'code' => 'VEN-'.fake()->unique()->numberBetween(1000, 9999),
            'name' => fake()->company(),
            'legal_name' => fake()->company().' Private Limited',
            'gstin' => fake()->unique()->numerify('27AA###AA#Z#').fake()->randomLetter(),
            'pan' => strtoupper(fake()->bothify('?????####?')),
            'contact_person' => fake()->name(),
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->numerify('9#########'),
            'address_line_1' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => 'Maharashtra',
            'pincode' => fake()->numerify('4#####'),
            'country' => 'India',
            'payment_terms_days' => fake()->randomElement([15, 30, 45, 60]),
            'supply_type' => fake()->randomElement(['raw_material', 'packaging', 'mixed']),
            'is_approved' => true,
            'is_active' => true,
        ];
    }

    public function unapproved(): static
    {
        return $this->state(fn () => ['is_approved' => false]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
