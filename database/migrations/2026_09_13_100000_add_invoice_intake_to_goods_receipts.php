<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The supplier's bill behind a goods receipt: the scanned document, what
 * was read from it, and whether the receipt was keyed by hand or came off
 * the scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table): void {
            $table->string('entry_mode', 12)->default('manual')->after('notes');
            $table->string('invoice_path')->nullable()->after('entry_mode');
            $table->string('invoice_name')->nullable()->after('invoice_path');
            $table->string('invoice_mime', 64)->nullable()->after('invoice_name');
            $table->jsonb('extraction')->nullable()->after('invoice_mime');
            $table->string('extraction_model', 64)->nullable()->after('extraction');
            $table->timestamp('extracted_at')->nullable()->after('extraction_model');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table): void {
            $table->dropColumn(['entry_mode', 'invoice_path', 'invoice_name', 'invoice_mime', 'extraction', 'extraction_model', 'extracted_at']);
        });
    }
};
