<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\Item;
use App\Domain\MasterData\Models\ItemCategory;
use App\Domain\Measurement\Enums\UomDimension;
use App\Domain\Measurement\Models\Uom;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('ITM-####')),
            'name' => fake()->words(3, true),
            'type' => ItemType::RawMaterial,
            'category_id' => null,
            'stock_uom_id' => self::uom('KG', UomDimension::Mass, '1000', 3),
            'purchase_uom_id' => null,
            'hsn_code' => (string) fake()->numberBetween(30000000, 39999999),
            'gst_rate' => fake()->randomElement(['5.000', '12.000', '18.000']),
            'standard_cost' => (string) fake()->numberBetween(50, 5000),
            'is_batch_tracked' => true,
            'requires_qc' => false,
            'is_active' => true,
        ];
    }

    /**
     * Resolve a unit of measure by code, creating it if this test or seed run
     * has not already.
     *
     * Factories that silently invent a fresh "KG" per item would leave a
     * database full of duplicate units and conversions that disagree.
     */
    public static function uom(string $code, UomDimension $dimension, string $factorToBase, int $scale = 3): int
    {
        $uom = Uom::firstOrCreate(
            ['code' => $code],
            [
                'name' => $code,
                'dimension' => $dimension,
                'is_base' => $factorToBase === '1',
                'factor_to_base' => $factorToBase,
                'display_scale' => $scale,
                'is_active' => true,
            ],
        );

        return $uom->getKey();
    }

    public function ofType(ItemType $type): static
    {
        return $this->state(fn () => ['type' => $type]);
    }

    public function inCategory(ItemCategory $category): static
    {
        return $this->state(fn () => ['category_id' => $category->getKey()]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function requiringQc(): static
    {
        return $this->state(fn () => ['requires_qc' => true]);
    }

    /**
     * A liquid material that can be dosed by volume although it is bought by
     * weight — the case the density bridge in UnitConversionService exists for.
     */
    public function withDensity(string $gramsPerMillilitre = '1.020000'): static
    {
        return $this->state(fn () => ['density_g_per_ml' => $gramsPerMillilitre]);
    }

    public function withStockUom(string $code, UomDimension $dimension, string $factorToBase): static
    {
        return $this->state(fn () => [
            'stock_uom_id' => self::uom($code, $dimension, $factorToBase),
        ]);
    }
}
