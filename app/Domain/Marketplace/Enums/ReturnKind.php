<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Enums;

/**
 * Why a parcel came back.
 *
 *   rto       — returned to origin: never delivered (refused, not at home,
 *               address not found) and sent back by the courier
 *   customer  — delivered, and the customer sent it back
 */
enum ReturnKind: string
{
    case Rto = 'rto';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::Rto => 'RTO (not delivered)',
            self::Customer => 'Customer return',
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
