<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Enums;

/**
 * What kind of thing an item is.
 *
 * The type decides which module maintains the item, which permissions guard
 * it, and which fields the form shows — but not which table it lives in. See
 * the items migration for why they share one.
 */
enum ItemType: string
{
    case RawMaterial = 'raw_material';
    case PackagingMaterial = 'packaging_material';
    case FinishedGood = 'finished_good';
    case SemiFinished = 'semi_finished';
    case Consumable = 'consumable';

    public function label(): string
    {
        return match ($this) {
            self::RawMaterial => 'Raw Material',
            self::PackagingMaterial => 'Packaging Material',
            self::FinishedGood => 'Finished Good',
            self::SemiFinished => 'Semi-Finished Good',
            self::Consumable => 'Consumable',
        };
    }

    /**
     * The permission module that governs this kind of item.
     */
    public function permissionModule(): string
    {
        return match ($this) {
            self::RawMaterial => 'raw_material',
            self::PackagingMaterial => 'packaging_material',
            self::FinishedGood, self::SemiFinished => 'product',
            self::Consumable => 'raw_material',
        };
    }

    /**
     * Whether items of this type can appear as an ingredient in a formula.
     */
    public function isFormulable(): bool
    {
        return in_array($this, [self::RawMaterial, self::SemiFinished], strict: true);
    }

    /**
     * Whether items of this type are sold.
     */
    public function isSaleable(): bool
    {
        return $this === self::FinishedGood;
    }
}
