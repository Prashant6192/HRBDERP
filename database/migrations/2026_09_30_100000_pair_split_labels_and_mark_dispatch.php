<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Myntra sends a day's labels and its tax invoices as two PDFs: the label
 * carries the courier barcode and the buyer, the invoice the product and
 * the order. The two are paired into one parcel by the buyer's name and
 * pincode, and the invoice's pages print right after its label.
 *
 * And the depot's flow settles on: scan = packed (Scanned), then the
 * parcels are marked Dispatched in bulk when the courier leaves. Parcels
 * the one-scan flow marked "with the courier" without a courier's
 * signature go back to Scanned, to be marked Dispatched by the depot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->string('customer_pincode', 10)->nullable()->after('customer_state');
            $table->foreignId('invoice_file_id')->nullable()->after('pages')->constrained('label_files')->nullOnDelete();
            $table->jsonb('invoice_pages')->nullable()->after('invoice_file_id');
        });

        DB::table('shipments')
            ->where('status', 'handed_over')
            ->whereNull('handover_sheet_id')
            ->update(['status' => 'packed', 'handed_over_at' => null, 'handed_over_by' => null]);
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('invoice_file_id');
            $table->dropColumn(['customer_pincode', 'invoice_pages']);
        });
    }
};
