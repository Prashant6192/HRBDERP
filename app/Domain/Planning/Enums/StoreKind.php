<?php

declare(strict_types=1);

namespace App\Domain\Planning\Enums;

use App\Domain\MasterData\Enums\ItemType;
use App\Domain\Warehousing\Enums\WarehouseType;

/**
 * Which store a requirement is drawn from.
 */
enum StoreKind: string
{
    case RawMaterial = 'raw_material';
    case Packaging = 'packaging';

    public function label(): string
    {
        return match ($this) {
            self::RawMaterial => 'Raw Material Store',
            self::Packaging => 'Packaging Material Store',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::RawMaterial => 'RM',
            self::Packaging => 'PM',
        };
    }

    public function warehouseType(): WarehouseType
    {
        return match ($this) {
            self::RawMaterial => WarehouseType::RawMaterial,
            self::Packaging => WarehouseType::Packaging,
        };
    }

    public static function forItemType(ItemType $type): self
    {
        return $type === ItemType::PackagingMaterial ? self::Packaging : self::RawMaterial;
    }
}
