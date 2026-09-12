<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\FacilityType;
use App\Domain\Warehousing\Models\Warehouse;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Facility>
 */
class FacilityFactory extends Factory
{
    protected $model = Facility::class;

    public function definition(): array
    {
        return [
            'code' => 'FAC-'.strtoupper(fake()->unique()->bothify('???-###')),
            'name' => fake()->city().' Warehouse',
            'facility_type_id' => fn () => self::typeId('WH'),
            'address_line_1' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => 'Uttarakhand',
            'pincode' => fake()->numerify('26####'),
            'country' => 'India',
            'can_store' => true,
            'can_receive' => true,
            'can_qc' => false,
            'can_manufacture' => false,
            'can_pack' => false,
            'can_dispatch' => true,
            'can_return' => false,
            'opening_stock_enabled' => true,
            'is_active' => true,
        ];
    }

    public function manufacturing(): static
    {
        return $this->state(fn () => [
            'name' => fake()->city().' Manufacturing Facility',
            'facility_type_id' => self::typeId('MFG'),
            'can_qc' => true,
            'can_manufacture' => true,
            'can_pack' => true,
            'can_return' => true,
        ]);
    }

    /**
     * A test that truncates tables loses the reference rows the migration
     * seeded; put them back rather than fail on a missing type.
     */
    private static function typeId(string $code): int
    {
        $id = FacilityType::query()->where('code', $code)->value('id');

        if ($id === null) {
            (new ReferenceDataSeeder)->run();
            $id = FacilityType::query()->where('code', $code)->value('id');
        }

        return (int) $id;
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * Give the facility one store of each of the given kinds.
     *
     * @param  list<WarehouseType>  $kinds
     */
    public function withStores(array $kinds): static
    {
        return $this->afterCreating(function (Facility $facility) use ($kinds): void {
            foreach ($kinds as $kind) {
                Warehouse::factory()->ofType($kind)->create([
                    'facility_id' => $facility->id,
                    'name' => "{$facility->name} {$kind->label()}",
                    'city' => $facility->city,
                ]);
            }
        });
    }
}
