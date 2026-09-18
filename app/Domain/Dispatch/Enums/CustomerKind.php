<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\Enums;

/**
 * Who a consignment goes to. The contract client is billed for their own
 * goods; a marketplace or distributor is sold ours.
 */
enum CustomerKind: string
{
    case Client = 'client';
    case Marketplace = 'marketplace';
    case Distributor = 'distributor';
    case Retailer = 'retailer';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Client => 'Contract client',
            self::Marketplace => 'E-commerce marketplace',
            self::Distributor => 'Distributor',
            self::Retailer => 'Retailer',
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
