<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The shop floor: scans that verify material before it is issued, stock
 * counts that reconcile the system with the shelf, and photos from the
 * floor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manufacturing_order_scans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('manufacturing_order_id')->constrained('manufacturing_orders')->cascadeOnDelete();
            $table->string('code', 191);
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('inventory_lots')->nullOnDelete();
            // ok: the material may go into the batch. blocked: it may not, and why.
            $table->string('verdict', 16);
            $table->jsonb('reasons');
            $table->foreignId('scanned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scanned_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['manufacturing_order_id', 'verdict']);
        });

        DB::statement("ALTER TABLE manufacturing_order_scans ADD CONSTRAINT mo_scans_verdict CHECK (verdict IN ('ok', 'blocked'))");

        Schema::create('stock_counts', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 16)->default('counting');
            $table->text('notes')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['warehouse_id', 'status']);
        });

        DB::statement("ALTER TABLE stock_counts ADD CONSTRAINT stock_counts_status CHECK (status IN ('counting', 'submitted', 'approved', 'cancelled'))");

        Schema::create('stock_count_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_count_id')->constrained('stock_counts')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('inventory_lots')->nullOnDelete();
            $table->decimal('system_quantity', 20, 6);
            $table->decimal('counted_quantity', 20, 6)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('counted_at')->nullable();
            $table->foreignId('inventory_transaction_id')->nullable()->constrained('inventory_transactions')->nullOnDelete();
            $table->timestamps();

            $table->unique(['stock_count_id', 'item_id', 'lot_id'], 'stock_count_lines_unique');
        });

        Schema::create('floor_photos', function (Blueprint $table): void {
            $table->id();
            $table->morphs('subject');
            $table->string('path');
            $table->string('note', 500)->nullable();
            $table->foreignId('taken_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('taken_at');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('floor_photos');
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('manufacturing_order_scans');
    }
};
