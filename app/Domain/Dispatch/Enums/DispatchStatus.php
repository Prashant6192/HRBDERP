<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\Enums;

/**
 * The life of a consignment: written up, invoiced, gone, arrived.
 */
enum DispatchStatus: string
{
    case Draft = 'draft';
    case Invoiced = 'invoiced';
    case Dispatched = 'dispatched';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Invoiced => 'Invoiced',
            self::Dispatched => 'Dispatched',
            self::Delivered => 'Delivered',
            self::Cancelled => 'Cancelled',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'neutral',
            self::Invoiced => 'warning',
            self::Dispatched => 'info',
            self::Delivered => 'success',
            self::Cancelled => 'neutral',
        };
    }

    /** Still ours to change: nothing has left yet. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Invoiced], strict: true);
    }

    /** The goods have gone. */
    public function hasLeft(): bool
    {
        return in_array($this, [self::Dispatched, self::Delivered], strict: true);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
