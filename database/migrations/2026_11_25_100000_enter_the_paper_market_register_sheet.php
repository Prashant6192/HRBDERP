<?php

declare(strict_types=1);

use App\Domain\Inventory\Services\StockCountService;
use App\Domain\Marketplace\Models\Brand;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * The Paper Market shelf as its register sheet of 24 November 2026 has it,
 * entered as a stock count that waits for approval. The sheet stands for
 * the shelf at the start of 25 November: whatever was taken for parcels
 * or scanned in from then on is allowed for when the count is approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        $oil = ['medicated'];
        $green = ['ratan', 'ratna', 'green'];
        $shampoo = ['satreetha', 'satritha', 'reetha'];
        $sukoon = ['sukoon', 'sukun'];
        $lal = ['lal tel', 'laal tel', 'lal tail'];
        $ml = fn (int $size): string => "{$size}\\s*ml";

        $rows = [
            ['label' => 'RR 200ml', 'any' => $oil, 'size' => $ml(200), 'quantity' => 235],
            ['label' => 'RR 300ml', 'any' => $oil, 'size' => $ml(300), 'quantity' => 845],
            ['label' => 'RR 500ml', 'any' => $oil, 'size' => $ml(500), 'quantity' => 683],
            ['label' => 'Green 300ml', 'any' => $green, 'size' => $ml(300), 'quantity' => 645],
            ['label' => 'Green 500ml', 'any' => $green, 'size' => $ml(500), 'quantity' => 788],
            ['label' => 'Satreetha 200ml', 'any' => $shampoo, 'none' => ['soap'], 'size' => $ml(200), 'quantity' => 937],
            ['label' => 'Satreetha 500ml', 'any' => $shampoo, 'none' => ['soap'], 'size' => $ml(500), 'quantity' => 79],
            ['label' => 'Satreetha 1 litre', 'any' => $shampoo, 'none' => ['soap'], 'size' => '1000\\s*ml|1\\s*(?:l|ltr|litre|liter)', 'quantity' => 284],
            ['label' => 'Sukoon 200ml', 'any' => $sukoon, 'size' => $ml(200), 'quantity' => 384],
            ['label' => 'Sukoon 300ml', 'any' => $sukoon, 'size' => $ml(300), 'quantity' => 131],
            ['label' => 'Sukoon 500ml', 'any' => $sukoon, 'size' => $ml(500), 'quantity' => 183],
            ['label' => 'Lauki 200ml', 'any' => ['lauki'], 'size' => $ml(200), 'quantity' => 30],
            ['label' => 'Lauki 300ml', 'any' => ['lauki'], 'size' => $ml(300), 'quantity' => 369],
            ['label' => 'Lauki 500ml', 'any' => ['lauki'], 'size' => $ml(500), 'quantity' => 27],
            ['label' => 'Lal Tel 100ml', 'any' => $lal, 'size' => $ml(100), 'quantity' => 76],
            ['label' => 'Lal Tel 200ml', 'any' => $lal, 'size' => $ml(200), 'quantity' => 468],
        ];

        try {
            $store = Brand::query()->where('code', 'RR')->first()?->defaultWarehouse;

            if ($store === null) {
                Log::warning('Register sheet not entered: the Rahat Rooh brand has no store.');

                return;
            }

            $count = app(StockCountService::class)->enterSheet(
                $store,
                $rows,
                CarbonImmutable::parse('2026-11-25 00:00', 'Asia/Kolkata')->utc(),
                'Paper Market register sheet dated 24-11-26, entered from its photo.',
            );

            Log::info("Register sheet entered as {$count->number} ({$count->status->value}).");
        } catch (Throwable $e) {
            Log::warning("Register sheet not entered: {$e->getMessage()}");
        }
    }

    public function down(): void
    {
        // Nothing to undo: the count is cancelled or approved on its own screen.
    }
};
