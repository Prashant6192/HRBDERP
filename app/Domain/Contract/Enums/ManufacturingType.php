<?php

declare(strict_types=1);

namespace App\Domain\Contract\Enums;

/**
 * Whose batch it is: the company's own brand, or a client's under a
 * contract manufacturing arrangement. The factory works the same way for
 * both; only what is asked at planning, whose material is used and who is
 * billed differ.
 */
enum ManufacturingType: string
{
    case Own = 'own';
    case ThirdParty = 'third_party';

    public function label(): string
    {
        return match ($this) {
            self::Own => 'Own brand — '.config('erp.company.brand'),
            self::ThirdParty => 'Third party',
        };
    }

    public function isThirdParty(): bool
    {
        return $this === self::ThirdParty;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $t) => ['value' => $t->value, 'label' => $t->label()], self::cases());
    }
}
