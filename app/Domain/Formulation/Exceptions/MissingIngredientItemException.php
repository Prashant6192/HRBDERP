<?php

declare(strict_types=1);

namespace App\Domain\Formulation\Exceptions;

use RuntimeException;

/**
 * A recipe line points at a material that is no longer on file.
 *
 * Deleting a material does not rewrite the recipes that name it, so the
 * recipe can outlive the master. Scaling such a recipe cannot produce a
 * quantity, and saying which line is orphaned is more use than failing.
 */
class MissingIngredientItemException extends RuntimeException
{
    /**
     * @param  list<int>  $lineNumbers
     */
    public function __construct(public readonly array $lineNumbers, string $message)
    {
        parent::__construct($message);
    }

    /**
     * @param  list<int>  $lineNumbers
     */
    public static function forLines(array $lineNumbers, string $formulaName): self
    {
        sort($lineNumbers);
        $count = count($lineNumbers);
        $lines = implode(', ', $lineNumbers);

        return new self($lineNumbers, sprintf(
            '%s refers to %s that %s been deleted (recipe line%s %s). Restore the material under the masters, or remove %s from the recipe and activate a new version, then plan the batch again.',
            $formulaName,
            $count === 1 ? 'a material' : "{$count} materials",
            $count === 1 ? 'has' : 'have',
            $count === 1 ? '' : 's',
            $lines,
            $count === 1 ? 'that line' : 'those lines',
        ));
    }
}
