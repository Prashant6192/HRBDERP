<?php

declare(strict_types=1);

namespace App\Domain\Formulation\Enums;

/**
 * The specification a material is bought against for a given recipe.
 */
enum MaterialGrade: string
{
    case InHouse = 'IH';
    case IndianPharmacopoeia = 'IP';
    case BritishPharmacopoeia = 'BP';
    case UnitedStatesPharmacopeia = 'USP';
    case EuropeanPharmacopoeia = 'EP';
    case CosmeticGrade = 'COS';
    case FoodGrade = 'FOOD';

    public function label(): string
    {
        return match ($this) {
            self::InHouse => 'In-house spec (IH)',
            self::IndianPharmacopoeia => 'Indian Pharmacopoeia (IP)',
            self::BritishPharmacopoeia => 'British Pharmacopoeia (BP)',
            self::UnitedStatesPharmacopeia => 'US Pharmacopeia (USP)',
            self::EuropeanPharmacopoeia => 'European Pharmacopoeia (EP)',
            self::CosmeticGrade => 'Cosmetic grade',
            self::FoodGrade => 'Food grade',
        };
    }

    public static function tryFromLabel(?string $value): ?self
    {
        $value = strtoupper(trim((string) $value));

        return $value === '' ? null : self::tryFrom($value);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $grade): array => ['value' => $grade->value, 'label' => $grade->label()],
            self::cases(),
        );
    }
}
