<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per carton scanned off a lorry at the destination. A carton is
 * its batch and its box number, so the same carton cannot be counted twice.
 * Scans are booked into the store together; booked_at says when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_carton_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignId('stock_transfer_line_id')->constrained('stock_transfer_lines')->cascadeOnDelete();
            $table->foreignId('lot_id')->constrained('inventory_lots')->restrictOnDelete();
            $table->unsignedInteger('box_no');
            $table->decimal('units', 20, 6);
            $table->string('code', 128);
            $table->string('device', 160)->nullable();
            $table->foreignId('scanned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scanned_at');
            $table->timestamp('booked_at')->nullable();
            $table->timestamps();

            $table->unique(['stock_transfer_id', 'lot_id', 'box_no']);
            $table->index(['stock_transfer_id', 'booked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_carton_scans');
    }
};
