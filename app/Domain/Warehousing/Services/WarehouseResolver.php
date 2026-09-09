<?php

declare(strict_types=1);

namespace App\Domain\Warehousing\Services;

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
}
