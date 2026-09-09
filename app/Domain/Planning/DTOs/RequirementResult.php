<?php

declare(strict_types=1);

namespace App\Domain\Planning\DTOs;

use Brick\Math\BigDecimal;

/**
 * A plan's material requirement, both stores, with the whole story of how
 * it was arrived at.
 */
final class RequirementResult
{
    /**
     * @param  list<RequirementLine>  $rawMaterials
     * @param  list<RequirementLine>  $packaging
     * @param  list<string>  $warnings
     */
    public function __construct(
        public BigDecimal $batchQuantity,
        public string $batchUomCode,
        public ?int $units,
        public array $rawMaterials = [],
        public array $packaging = [],
        public array $warnings = [],
    ) {}

    /**
     * @return list<RequirementLine>
     */
    public function lines(): array
    {
        return [...$this->rawMaterials, ...$this->packaging];
    }

    public function hasShortage(): bool
    {
        foreach ($this->lines() as $line) {
            if ($line->isShort()) {
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
            'batch_quantity' => $this->batchQuantity->__toString(),
            'batch_uom' => $this->batchUomCode,
            'units' => $this->units,
            'raw_materials' => array_map(static fn (RequirementLine $l): array => $l->toArray(), $this->rawMaterials),
            'packaging' => array_map(static fn (RequirementLine $l): array => $l->toArray(), $this->packaging),
            'warnings' => $this->warnings,
            'has_shortage' => $this->hasShortage(),
        ];
    }
}
