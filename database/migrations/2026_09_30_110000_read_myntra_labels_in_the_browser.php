<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Myntra's label and invoice PDFs are pictures. They are no longer sent to
 * the AI reader: the uploader's browser reads their words and barcodes and
 * the ERP's Myntra parser makes the parcels. Only the marketplace's reader
 * setting changes; no order, file or stock record is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('marketplaces')->where('code', 'MYNTRA')->update(['reader' => 'myntra', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('marketplaces')->where('code', 'MYNTRA')->update(['reader' => 'ai', 'updated_at' => now()]);
    }
};
