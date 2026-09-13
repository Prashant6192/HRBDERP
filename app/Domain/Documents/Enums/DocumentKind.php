<?php

declare(strict_types=1);

namespace App\Domain\Documents\Enums;

enum DocumentKind: string
{
    case Sop = 'sop';
    case Specification = 'specification';
    case Artwork = 'artwork';
    case Coa = 'coa';
    case Formula = 'formula';
    case QcStandard = 'qc_standard';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Sop => 'SOP',
            self::Specification => 'Specification',
            self::Artwork => 'Artwork',
            self::Coa => 'Certificate of analysis',
            self::Formula => 'Formula document',
            self::QcStandard => 'QC standard',
            self::Other => 'Other',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $k) => ['value' => $k->value, 'label' => $k->label()], self::cases());
    }
}
