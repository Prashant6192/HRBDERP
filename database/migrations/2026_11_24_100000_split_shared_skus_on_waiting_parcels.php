<?php

declare(strict_types=1);

use App\Domain\Marketplace\Services\OnlineOrderService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Website labels print a product's number where a variant has no SKU, the
 * same number for every size and pack, so a 200ml and a 1000ml Pack of 2
 * were mapped as one product. New uploads now carry the variant in the
 * SKU; this puts the parcels still waiting right the same way. They wait
 * as "not mapped" until each new SKU is mapped once.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            $changed = app(OnlineOrderService::class)->splitSharedSkus();
            Log::info("Split shared SKUs on {$changed} waiting parcel(s).");
        } catch (Throwable $e) {
            // Never stop a deploy for this: the parcels can be matched again from SKU mapping.
            Log::error('Could not split shared SKUs on waiting parcels: '.$e->getMessage());
        }
    }

    public function down(): void
    {
        // The SKUs stay as they are.
    }
};
