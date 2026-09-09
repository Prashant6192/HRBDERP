<?php

declare(strict_types=1);

namespace App\Domain\Formulation\DTOs;

use Brick\Math\BigDecimal;

/**
 * One recipe line worked out for a real batch.
 */
final readonly class ScaledIngredient
{
    public function __construct(
        public int $lineNo,
        public int $itemId,
        public string $itemCode,
        public string $itemName,
        public ?string $inciName,
        public ?string $grade,
        public ?string $purpose,
        public ?BigDecimal $percentage,
        public bool $isQs,
        public bool $asRequired,
        /** In the batch unit the recipe was written in. Null when "as required". */
        public ?BigDecimal $quantity,
        public string $batchUomCode,
        /** The same quantity in the unit the material is stocked in, when convertible. */
        public ?BigDecimal $stockQuantity,
        public string $stockUomCode,
        public bool $converted,
        /** The stock quantity was worked out at 1 g/ml because the material has no density. */
        public bool $assumedDensity = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'line_no' => $this->lineNo,
            'item_id' => $this->itemId,
            'item_code' => $this->itemCode,
            'item_name' => $this->itemName,
            'inci_name' => $this->inciName,
            'grade' => $this->grade,
            'purpose' => $this->purpose,
            'percentage' => $this->percentage?->__toString(),
            'is_qs' => $this->isQs,
            'as_required' => $this->asRequired,
            'quantity' => $this->quantity?->__toString(),
            'batch_uom' => $this->batchUomCode,
            'stock_quantity' => $this->stockQuantity?->__toString(),
            'stock_uom' => $this->stockUomCode,
            'converted' => $this->converted,
            'assumed_density' => $this->assumedDensity,
        ];
    }
}
