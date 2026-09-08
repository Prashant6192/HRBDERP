<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\ItemCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemCategory>
 */
class ItemCategoryFactory extends Factory
{
    protected $model = ItemCategory::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('CAT###')),
            'name' => fake()->words(2, true),
            'item_type' => null,
            'parent_id' => null,
            'is_active' => true,
        ];
    }

    public function forType(ItemType $type): static
    {
        return $this->state(fn () => ['item_type' => $type]);
    }
}
