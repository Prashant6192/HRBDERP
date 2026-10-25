<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Orders from the company's own website, shipped through Shiprocket: a
 * marketplace whose labels are Shiprocket's. Added once; nothing else is
 * touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('marketplaces')->where('code', 'WEBSITE')->exists()) {
            return;
        }

        DB::table('marketplaces')->insert([
            'code' => 'WEBSITE',
            'name' => 'Website',
            'reader' => 'shiprocket',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Left in place: parcels may already point at it.
    }
};
