<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Enums;

/**
 * A parcel's day: its label uploaded, printed, the goods packed against it,
 * handed to the courier. Or cancelled, with a reason; or, after it left,
 * returned.
 */
enum ShipmentStatus: string
{
    case Uploaded = 'uploaded';
    case Printed = 'printed';
    case Packed = 'packed';
    case HandedOver = 'handed_over';
    case Cancelled = 'cancelled';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Not printed',
            self::Printed => 'Printed, not packed',
            self::Packed => 'Packed',
            self::HandedOver => 'Handed to courier',
            self::Cancelled => 'Cancelled',
            self::Returned => 'Returned',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Uploaded => 'neutral',
            self::Printed => 'warning',
            self::Packed => 'info',
            self::HandedOver => 'success',
            self::Cancelled => 'neutral',
            self::Returned => 'danger',
        };
    }

    /** Still waiting for someone to pack it. */
    public function awaitsPacking(): bool
    {
        return in_array($this, [self::Uploaded, self::Printed], strict: true);
    }

    /** Its stock has left. */
    public function isPacked(): bool
    {
        return in_array($this, [self::Packed, self::HandedOver], strict: true);
    }

    /** It has left the depot, so it can come back as a return. */
    public function canBeReturned(): bool
    {
        return in_array($this, [self::Packed, self::HandedOver], strict: true);
    }

    /**
     * @return list<string>
     */
    public static function awaitingPacking(): array
    {
        return [self::Uploaded->value, self::Printed->value];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
