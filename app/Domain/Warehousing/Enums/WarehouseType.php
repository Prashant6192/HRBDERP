<?php

declare(strict_types=1);

namespace App\Domain\Warehousing\Enums;

enum WarehouseType: string
{
    case RawMaterial = 'raw_material';
    case Packaging = 'packaging';
    case FinishedGoods = 'finished_goods';
    case Quarantine = 'quarantine';
    case Marketplace = 'marketplace';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::RawMaterial => 'Raw Material Store',
            self::Packaging => 'Packaging Store',
            self::FinishedGoods => 'Finished Goods Store',
            self::Quarantine => 'Quarantine',
            self::Marketplace => 'Marketplace',
            self::General => 'General',
        };
    }

    /**
     * Stock here is on the books but not available to production or sales
     * until quality control releases it.
     */
    public function holdsQuarantinedStock(): bool
    {
        return $this === self::Quarantine;
    }
}
