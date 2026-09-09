<?php

declare(strict_types=1);

namespace App\Domain\Formulation\DTOs;

use Brick\Math\BigDecimal;

/**
 * A recipe scaled to a batch: every line, plus the totals that tell a
 * planner whether the recipe accounts for the whole batch.
 */
final readonly class ScaledBatch
{
    /**
     * @param  list<ScaledIngredient>  $lines
     */
    public function __construct(
        public int $formulaVersionId,
        public BigDecimal $batchQuantity,
        public string $batchUomCode,
        public array $lines,
        public BigDecimal $fixedPercentage,
        public ?BigDecimal $qsPercentage,
    ) {}

    public function isComplete(): bool
    {
        return $this->qsPercentage !== null || $this->fixedPercentage->isEqualTo(100);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'formula_version_id' => $this->formulaVersionId,
            'batch_quantity' => $this->batchQuantity->__toString(),
            'batch_uom' => $this->batchUomCode,
            'fixed_percentage' => $this->fixedPercentage->__toString(),
            'qs_percentage' => $this->qsPercentage?->__toString(),
            'complete' => $this->isComplete(),
            'lines' => array_map(static fn (ScaledIngredient $line): array => $line->toArray(), $this->lines),
        ];
    }
}
