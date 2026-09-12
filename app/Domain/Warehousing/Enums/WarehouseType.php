<?php

declare(strict_types=1);

namespace App\Domain\Warehousing\Enums;

/**
 * What a store holds — the behaviour behind a store category.
 *
 * Store categories are configurable master data; each one maps to one of
 * these kinds, which is what the workflows key on. An administrator may add
 * "Bulk Store" as a category of kind raw_material without any code change.
 */
enum WarehouseType: string
{
    case RawMaterial = 'raw_material';
    case Packaging = 'packaging';
    case FinishedGoods = 'finished_goods';
    case Quarantine = 'quarantine';
    case Rejected = 'rejected';
    case ProductionStaging = 'production_staging';
    case PackagingStaging = 'packaging_staging';
    case Samples = 'samples';
    case Returns = 'returns';
    case Damaged = 'damaged';
    case Marketplace = 'marketplace';
    case InTransit = 'in_transit';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::RawMaterial => 'Raw Material Store',
            self::Packaging => 'Packaging Store',
            self::FinishedGoods => 'Finished Goods Store',
            self::Quarantine => 'Quarantine',
            self::Rejected => 'Rejected Material',
            self::ProductionStaging => 'Production Staging',
            self::PackagingStaging => 'Packaging Staging',
            self::Samples => 'Samples',
            self::Returns => 'Returns',
            self::Damaged => 'Damaged Goods',
            self::Marketplace => 'Marketplace',
            self::InTransit => 'In Transit',
            self::General => 'General',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::RawMaterial => 'RM',
            self::Packaging => 'PM',
            self::FinishedGoods => 'FG',
            self::Quarantine => 'QUAR',
            self::Rejected => 'REJ',
            self::ProductionStaging => 'PROD',
            self::PackagingStaging => 'PACK',
            self::Samples => 'SMPL',
            self::Returns => 'RET',
            self::Damaged => 'DMG',
            self::Marketplace => 'MKT',
            self::InTransit => 'TRANSIT',
            self::General => 'GEN',
        };
    }

    /**
     * Stock here is on the books but not available to production or sales:
     * awaiting QC, rejected, damaged, or on a lorry between facilities.
     */
    public function holdsQuarantinedStock(): bool
    {
        return in_array($this, [self::Quarantine, self::Rejected, self::Damaged, self::InTransit], strict: true);
    }
}
