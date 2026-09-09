<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A lot is one batch of one item: the physical quantity that arrived on one
 * receipt or came out of one manufacturing run, under one batch number.
 *
 * Stock is held per lot so that expiry, quality status and traceability all
 * have somewhere to live. A finished batch can be traced back to the exact
 * drums of raw material that went into it, and a rejected drum cannot be
 * issued to production because the balance it belongs to is marked as such.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_lots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();

            $table->string('batch_number', 64)->unique();
            $table->string('supplier_batch_ref', 128)->nullable();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();

            $table->date('manufactured_at')->nullable();
            $table->date('received_at')->nullable();
            $table->date('expiry_at')->nullable();

            // 'pending' | 'approved' | 'rejected' | 'on_hold' | 'not_required'
            $table->string('qc_status', 16)->default('pending');
            $table->timestamp('qc_decided_at')->nullable();
            $table->foreignId('qc_decided_by')->nullable()->constrained('users')->nullOnDelete();

            $table->decimal('initial_quantity', 20, 6);
            $table->decimal('unit_cost', 20, 4)->nullable();

            // Where the lot came from: a goods receipt line, or a manufacturing
            // order. Not a constrained relation — a lot must outlive its source.
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['item_id', 'qc_status']);
            $table->index('expiry_at');
            $table->index(['source_type', 'source_id']);
        });

        DB::statement('ALTER TABLE inventory_lots ADD CONSTRAINT inventory_lots_initial_quantity_positive CHECK (initial_quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_lots');
    }
};
