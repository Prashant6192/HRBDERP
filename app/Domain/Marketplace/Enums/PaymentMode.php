<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Enums;

enum PaymentMode: string
{
    case Cod = 'cod';
    case Prepaid = 'prepaid';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Cod => 'COD',
            self::Prepaid => 'Prepaid',
            self::Unknown => '—',
        };
    }

    public static function fromText(?string $value): self
    {
        $value = strtolower(trim((string) $value));

        return match (true) {
            $value === '' => self::Unknown,
            str_contains($value, 'prepaid') => self::Prepaid,
            $value === 'cod' || str_contains($value, 'cash on delivery') || str_contains($value, 'cod') => self::Cod,
            default => self::tryFrom($value) ?? self::Unknown,
        };
    }
}
