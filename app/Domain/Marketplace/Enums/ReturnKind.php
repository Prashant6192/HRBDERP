<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Enums;

/**
 * Why a parcel came back.
 *
 *   customer  — the parcel came back and its goods are counted back in
 *   rto       — returned to origin, never delivered: no longer offered
 *               (every parcel that comes back is a customer return now),
 *               kept so older returns still read as they were received
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
     * The kinds a new return may be received as.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return [['value' => self::Customer->value, 'label' => self::Customer->label()]];
    }
}
