<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Exceptions;

use App\Domain\MasterData\Models\Item;
use App\Domain\Measurement\Models\Uom;
use RuntimeException;

/**
 * Thrown when a conversion is asked for that no unit definition can justify —
 * litres into kilograms for a material with no declared density, say.
 *
 * This is deliberately an exception rather than a null return: a quantity that
 * silently failed to convert would go on to move stock.
 */
final class IncompatibleUnitsException extends RuntimeException
{
    public static function between(Uom $from, Uom $to): self
    {
        return new self(sprintf(
            'Cannot convert %s (%s) to %s (%s): different dimensions and no density is available for the item.',
            $from->code,
            $from->dimension->value,
            $to->code,
            $to->dimension->value,
        ));
    }

    /**
     * A pack unit was used without the item-specific factor that gives it a
     * size. Converting anyway would invent a number.
     */
    public static function missingItemFactor(Uom $from, Uom $to, ?Item $item): self
    {
        $packUnit = $from->needsItemFactor() ? $from : $to;

        return new self(sprintf(
            'Cannot convert %s to %s: %s is sized per item, and %s. '
            .'Define the conversion on the item before using this unit.',
            $from->code,
            $to->code,
            $packUnit->code,
            $item === null
                ? 'no item was supplied'
                : sprintf('item %s does not define one', $item->code),
        ));
    }
}
