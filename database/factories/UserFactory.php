<?php

namespace Database\Factories;

use App\Domain\Identity\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_code' => 'EMP-'.fake()->unique()->numberBetween(1000, 9999),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'designation' => fake()->jobTitle(),
            'phone' => fake()->numerify('9#########'),
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * An account that has been deactivated but not deleted — the state a
     * departing employee's record ends up in.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::Inactive,
            'deactivated_at' => now(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::Suspended,
        ]);
    }

    /**
     * An account that has set the second-factor PIN guarding formulations.
     */
    public function withFormulaPin(string $pin = '135790'): static
    {
        return $this->state(fn (array $attributes) => [
            'formula_pin_hash' => Hash::make($pin),
            'formula_pin_set_at' => now(),
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
