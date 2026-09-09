<?php

declare(strict_types=1);

namespace App\Domain\Warehousing\Services;

use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Warehouse;
use RuntimeException;

/**
 * Finds the warehouses the workflow relies on by role rather than by id.
 */
class WarehouseResolver
{
    /**
     * Where received stock waits for quality.
     */
    public function quarantine(): Warehouse
    {
        $warehouse = Warehouse::query()
            ->where('is_active', true)
            ->where('is_quarantine', true)
            ->orderBy('id')
            ->first();

        if ($warehouse === null) {
            throw new RuntimeException(
                'No active quarantine warehouse is configured. Create one under Warehouses and tick "Quarantine store".'
            );
        }

        return $warehouse;
    }

    /**
     * The store production draws raw materials from.
     */
    public function rawMaterialStore(): Warehouse
    {
        return $this->storeOfType(WarehouseType::RawMaterial, 'raw material');
    }

    /**
     * The store packaging is picked from.
     */
    public function packagingStore(): Warehouse
    {
        return $this->storeOfType(WarehouseType::Packaging, 'packaging');
    }

    /**
     * Where finished batches go.
     */
    public function finishedGoodsStore(): Warehouse
    {
        return $this->storeOfType(WarehouseType::FinishedGoods, 'finished goods');
    }

    public function findStoreOfType(WarehouseType $type): ?Warehouse
    {
        return Warehouse::query()
            ->where('is_active', true)
            ->where('is_quarantine', false)
            ->where('type', $type->value)
            ->orderBy('id')
            ->first();
    }

    private function storeOfType(WarehouseType $type, string $label): Warehouse
    {
        $warehouse = $this->findStoreOfType($type);

        if ($warehouse === null) {
            throw new RuntimeException(
                "No active {$label} store is configured. Create one under Warehouses with the type \"{$type->label()}\"."
            );
        }

        return $warehouse;
    }
}
