<?php

declare(strict_types=1);

namespace App\Domain\Contract\Enums;

/**
 * Who a recipe belongs to. A client-owned formula is the client's
 * confidential property and may only ever be made for that client; a joint
 * formula was developed together and stays with the client's jobs too; a
 * company formula may be made for anyone.
 */
enum FormulaOwnership: string
{
    case Company = 'company';
    case Client = 'client';
    case Joint = 'joint';

    public function label(): string
    {
        return match ($this) {
            self::Company => 'Company owned',
            self::Client => 'Client owned',
            self::Joint => 'Joint / contract',
        };
    }

    /**
     * A formula tied to a client is made for that client and nobody else.
     */
    public function isTiedToClient(): bool
    {
        return $this !== self::Company;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $o) => ['value' => $o->value, 'label' => $o->label()], self::cases());
    }
}
