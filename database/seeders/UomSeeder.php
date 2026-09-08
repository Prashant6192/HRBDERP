<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Measurement\Enums\UomDimension;
use App\Domain\Measurement\Models\Uom;
use Illuminate\Database\Seeder;

/**
 * The units the ERP ships with.
 *
 * Factors are relative to each dimension's base unit — gram, millilitre and
 * piece — and are exact by construction. Pack units (box, carton) are flagged
 * as needing an item-specific factor, because their size is a property of what
 * is inside them.
 */
class UomSeeder extends Seeder
{
    /**
     * @var list<array{code: string, name: string, dimension: UomDimension, factor: string, scale: int, base?: bool, pack?: bool}>
     */
    private const UNITS = [
        // ---- Mass (base: gram) --------------------------------------------
        ['code' => 'G', 'name' => 'Gram', 'dimension' => UomDimension::Mass, 'factor' => '1', 'scale' => 3, 'base' => true],
        ['code' => 'MG', 'name' => 'Milligram', 'dimension' => UomDimension::Mass, 'factor' => '0.001', 'scale' => 3],
        ['code' => 'KG', 'name' => 'Kilogram', 'dimension' => UomDimension::Mass, 'factor' => '1000', 'scale' => 3],
        ['code' => 'MT', 'name' => 'Metric Tonne', 'dimension' => UomDimension::Mass, 'factor' => '1000000', 'scale' => 4],

        // ---- Volume (base: millilitre) -------------------------------------
        ['code' => 'ML', 'name' => 'Millilitre', 'dimension' => UomDimension::Volume, 'factor' => '1', 'scale' => 2, 'base' => true],
        ['code' => 'L', 'name' => 'Litre', 'dimension' => UomDimension::Volume, 'factor' => '1000', 'scale' => 3],
        ['code' => 'KL', 'name' => 'Kilolitre', 'dimension' => UomDimension::Volume, 'factor' => '1000000', 'scale' => 4],

        // ---- Count (base: piece) -------------------------------------------
        ['code' => 'PCS', 'name' => 'Pieces', 'dimension' => UomDimension::Count, 'factor' => '1', 'scale' => 0, 'base' => true],
        ['code' => 'DOZ', 'name' => 'Dozen', 'dimension' => UomDimension::Count, 'factor' => '12', 'scale' => 0],
        ['code' => 'GRS', 'name' => 'Gross', 'dimension' => UomDimension::Count, 'factor' => '144', 'scale' => 0],

        // Pack units. The factor stored here is a placeholder; conversion is
        // refused unless the item defines its own. See the
        // add_requires_item_factor_to_uoms migration.
        ['code' => 'BOX', 'name' => 'Box', 'dimension' => UomDimension::Count, 'factor' => '1', 'scale' => 0, 'pack' => true],
        ['code' => 'CTN', 'name' => 'Carton', 'dimension' => UomDimension::Count, 'factor' => '1', 'scale' => 0, 'pack' => true],
        ['code' => 'BAG', 'name' => 'Bag', 'dimension' => UomDimension::Count, 'factor' => '1', 'scale' => 0, 'pack' => true],
        ['code' => 'DRUM', 'name' => 'Drum', 'dimension' => UomDimension::Count, 'factor' => '1', 'scale' => 0, 'pack' => true],
    ];

    public function run(): void
    {
        foreach (self::UNITS as $unit) {
            Uom::updateOrCreate(
                ['code' => $unit['code']],
                [
                    'name' => $unit['name'],
                    'dimension' => $unit['dimension'],
                    'is_base' => $unit['base'] ?? false,
                    'requires_item_factor' => $unit['pack'] ?? false,
                    'factor_to_base' => $unit['factor'],
                    'display_scale' => $unit['scale'],
                    'is_active' => true,
                ],
            );
        }
    }
}
