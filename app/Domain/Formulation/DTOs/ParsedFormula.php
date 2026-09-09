<?php

declare(strict_types=1);

namespace App\Domain\Formulation\DTOs;

use Brick\Math\BigDecimal;

/**
 * One sheet of a formulation workbook, understood.
 */
final class ParsedFormula
{
    /**
     * @param  list<ParsedIngredient>  $ingredients
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $sheet,
        public string $name,
        public string $layout,
        public string $batchSize = '100',
        public string $batchUomCode = 'G',
        public array $ingredients = [],
        public array $warnings = [],
    ) {}

    public function totalPercentage(): BigDecimal
    {
        $total = BigDecimal::zero();

        foreach ($this->ingredients as $ingredient) {
            if ($ingredient->percentage !== null) {
                $total = $total->plus(BigDecimal::of($ingredient->percentage));
            }
        }

        return $total;
    }

    public function hasQs(): bool
    {
        foreach ($this->ingredients as $ingredient) {
            if ($ingredient->isQs) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sheet' => $this->sheet,
            'name' => $this->name,
            'layout' => $this->layout,
            'batch_size' => $this->batchSize,
            'batch_uom' => $this->batchUomCode,
            'total_percentage' => $this->totalPercentage()->__toString(),
            'has_qs' => $this->hasQs(),
            'ingredients' => array_map(static fn (ParsedIngredient $i): array => $i->toArray(), $this->ingredients),
            'warnings' => $this->warnings,
        ];
    }
}
