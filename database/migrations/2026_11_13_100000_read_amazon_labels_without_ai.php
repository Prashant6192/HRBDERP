<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Amazon's label PDFs are no longer sent to the AI reader. Each label is a
 * picture whose AWB barcode the uploader's browser reads; the tax invoice
 * after it is text the ERP reads exactly. Only the marketplace's reader
 * setting changes; no order, file or stock record is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('marketplaces')->where('code', 'AMAZON')->where('reader', 'ai')->update(['reader' => 'amazon', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('marketplaces')->where('code', 'AMAZON')->where('reader', 'amazon')->update(['reader' => 'ai', 'updated_at' => now()]);
    }
};
