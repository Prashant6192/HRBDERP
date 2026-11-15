<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Website labels read before numeric store SKUs were understood carry the
 * product's title run into the SKU ("67962894614801 Rahat Rooh Lauki
 * Oil…"). Lines not yet mapped are split back into the SKU and its title,
 * so each SKU is mapped once. Mapped lines, and every other record, are
 * left as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        $website = DB::table('marketplaces')->where('code', 'WEBSITE')->value('id');

        if ($website === null) {
            return;
        }

        DB::table('shipment_lines')
            ->whereNull('listing_id')
            ->whereIn('shipment_id', DB::table('shipments')->select('id')->where('marketplace_id', $website))
            ->select(['id', 'seller_sku', 'description'])
            ->chunkById(500, function ($lines): void {
                foreach ($lines as $line) {
                    if (preg_match('/^(\d{6,20})\s+(\S.*)$/s', (string) $line->seller_sku, $m) !== 1) {
                        continue;
                    }

                    $title = trim((string) preg_replace('/^\d{6,20}\s+/', '', (string) $line->description));

                    DB::table('shipment_lines')->where('id', $line->id)->update([
                        'seller_sku' => $m[1],
                        'description' => mb_substr($title !== '' ? $title : trim($m[2]), 0, 250),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // The split is not undone: the joined value was a misreading.
    }
};
