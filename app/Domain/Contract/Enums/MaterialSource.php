<?php

declare(strict_types=1);

namespace App\Domain\Contract\Enums;

/**
 * Where a third-party batch's materials come from: all ours, all the
 * client's, or some of each. With "client" or "mixed" the plan names the
 * materials the client supplies; those count only the client's own stock.
 */
enum MaterialSource: string
{
    case Company = 'company';
    case Client = 'client';
    case Mixed = 'mixed';

    public function label(): string
    {
        return match ($this) {
            self::Company => 'Our material',
            self::Client => 'Client supplied',
            self::Mixed => 'Mixed',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
