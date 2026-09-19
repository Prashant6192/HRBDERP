<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two things a packaging line needs.
 *
 * Batch reconciliation: what a batch was planned to give, what the kettle
 * gave, what the line filled, what it rejected, what was kept as samples
 * and what bulk was left over — so that 500 kg planned for 5,000 tubes
 * ending as 4,900 good tubes is on record line by line, not as one
 * "yield" figure.
 *
 * Artwork on any product: the client_artworks table was the client's;
 * it now holds the company's own brand too (client_id null), so the
 * packing line sees how the pack must look for every batch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manufacturing_orders', function (Blueprint $table): void {
            // Units the line filled before inspection; output_units stays
            // the good units that went to stock.
            $table->unsignedInteger('filled_units')->nullable()->after('output_units');
            $table->unsignedInteger('rejected_units')->nullable()->after('filled_units');
            $table->unsignedInteger('sample_units')->nullable()->after('rejected_units');
            // Bulk made but not packed, in the planned unit.
            $table->decimal('bulk_leftover_quantity', 20, 6)->nullable()->after('sample_units');
            $table->decimal('packing_yield_percentage', 8, 3)->nullable()->after('yield_percentage');
            $table->decimal('overall_yield_percentage', 8, 3)->nullable()->after('packing_yield_percentage');
            $table->string('loss_notes', 1000)->nullable()->after('overall_yield_percentage');
        });

        DB::statement('ALTER TABLE manufacturing_orders ADD CONSTRAINT mo_reconciliation_sane CHECK (
            (rejected_units IS NULL OR rejected_units >= 0) AND
            (sample_units IS NULL OR sample_units >= 0) AND
            (bulk_leftover_quantity IS NULL OR bulk_leftover_quantity >= 0)
        )');

        Schema::table('client_artworks', function (Blueprint $table): void {
            $table->foreignId('client_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE manufacturing_orders DROP CONSTRAINT IF EXISTS mo_reconciliation_sane');

        Schema::table('manufacturing_orders', function (Blueprint $table): void {
            $table->dropColumn(['filled_units', 'rejected_units', 'sample_units', 'bulk_leftover_quantity', 'packing_yield_percentage', 'overall_yield_percentage', 'loss_notes']);
        });
    }
};
