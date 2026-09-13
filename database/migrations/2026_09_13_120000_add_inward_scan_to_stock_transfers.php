<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The inward gate on a stock transfer: the code printed under the QR on the
 * dispatch challan, when and by whom it was scanned at the destination, and
 * the transport document that came with the consignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table): void {
            $table->string('challan_code', 16)->nullable()->unique()->after('vehicle_ref');
            $table->timestamp('scanned_at')->nullable()->after('dispatched_at');
            $table->foreignId('scanned_by')->nullable()->after('scanned_at')->constrained('users')->nullOnDelete();
            $table->string('transporter', 128)->nullable()->after('scanned_by');
            $table->string('transport_reference', 64)->nullable()->after('transporter');
            $table->string('transport_document_path')->nullable()->after('transport_reference');
            $table->string('transport_document_name')->nullable()->after('transport_document_path');
            $table->string('transport_document_mime', 64)->nullable()->after('transport_document_name');
            $table->jsonb('transport_extraction')->nullable()->after('transport_document_mime');
        });

        // A consignment already on the road gets a code, so the source can
        // print its challan and the destination can scan it.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        foreach (DB::table('stock_transfers')->whereIn('status', ['dispatched', 'in_transit', 'partially_received'])->pluck('id') as $id) {
            do {
                $code = '';

                for ($i = 0; $i < 8; $i++) {
                    $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                }
            } while (DB::table('stock_transfers')->where('challan_code', $code)->exists());

            DB::table('stock_transfers')->where('id', $id)->update(['challan_code' => $code]);
        }
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('scanned_by');
            $table->dropColumn([
                'challan_code', 'scanned_at', 'transporter', 'transport_reference',
                'transport_document_path', 'transport_document_name', 'transport_document_mime', 'transport_extraction',
            ]);
        });
    }
};
