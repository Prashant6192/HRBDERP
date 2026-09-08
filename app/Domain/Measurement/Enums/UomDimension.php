<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Enums;

/**
 * The physical dimensions the ERP measures in.
 *
 * Units convert freely within a dimension and never across one, except through
 * a material's declared density, which is the only physical fact that relates
 * mass to volume.
 */
enum UomDimension: string
{
    case Mass = 'mass';
    case Volume = 'volume';
    case Count = 'count';

    public function label(): string
    {
        return match ($this) {
            self::Mass => 'Mass',
            self::Volume => 'Volume',
            self::Count => 'Count',
        };
    }

    /**
     * The canonical unit every factor in this dimension is expressed against.
     */
    public function baseUnitCode(): string
    {
        return match ($this) {
            self::Mass => 'G',
            self::Volume => 'ML',
            self::Count => 'PCS',
        };
    }
}
