<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Contract\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'code' => 'TP-'.fake()->unique()->numberBetween(100, 999),
            'name' => fake()->company(),
            'legal_name' => fake()->company().' Private Limited',
            'gstin' => fake()->unique()->numerify('07AA###AA#Z#').fake()->randomLetter(),
            'contact_person' => fake()->name(),
            'phone' => fake()->numerify('9#########'),
            'email' => fake()->unique()->companyEmail(),
            'billing_address_line_1' => fake()->streetAddress(),
            'billing_city' => 'New Delhi',
            'billing_state' => 'Delhi',
            'billing_pincode' => fake()->numerify('11####'),
            'payment_terms_days' => 30,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
