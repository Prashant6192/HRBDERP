<?php

declare(strict_types=1);

namespace App\Domain\Planning\DTOs;

use App\Domain\Inventory\Enums\StockAlertLevel;
use App\Domain\Planning\Enums\StoreKind;
use Brick\Math\BigDecimal;

/**
 * One material a batch needs, set against what the store can give.
 *
 * Every quantity is in the material's stock unit, so "required" and
 * "available" compare like for like.
 */
final readonly class RequirementLine
{
    /**
     * @param  list<string>  $notes
     */
    public function __construct(
        public StoreKind $storeKind,
        public int $itemId,
        public string $itemCode,
        public string $itemName,
        public int $uomId,
        public string $uomCode,
        public ?BigDecimal $percentage,
        public bool $isQs,
        public bool $asRequired,
        public BigDecimal $required,
        public BigDecimal $available,
        public BigDecimal $shortage,
        public BigDecimal $restock,
        public StockAlertLevel $levelNow,
        public StockAlertLevel $levelAfter,
        public array $notes = [],
    ) {}

    public function isShort(): bool
    {
        return $this->shortage->isPositive();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'store_kind' => $this->storeKind->value,
            'item_id' => $this->itemId,
            'item_code' => $this->itemCode,
            'item_name' => $this->itemName,
            'uom_id' => $this->uomId,
            'uom' => $this->uomCode,
            'percentage' => $this->percentage?->__toString(),
            'is_qs' => $this->isQs,
            'as_required' => $this->asRequired,
            'required' => $this->required->__toString(),
            'available' => $this->available->__toString(),
            'shortage' => $this->shortage->__toString(),
            'restock' => $this->restock->__toString(),
            'level_now' => $this->levelNow->value,
            'level_after' => $this->levelAfter->value,
            'notes' => $this->notes,
        ];
    }
}
