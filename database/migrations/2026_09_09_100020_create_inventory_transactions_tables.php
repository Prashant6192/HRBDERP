<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The inventory ledger.
 *
 * Every movement of stock is a transaction with one or more signed lines.
 * Rows are only ever inserted: a mistake is corrected by a further
 * transaction, never by editing the one that was wrong. Current stock is the
 * sum of lines — stock_balances caches that sum for speed, but if the two ever
 * disagree the ledger is right and the cache is rebuilt from it.
 *
 * In production, revoke UPDATE and DELETE on both tables from the application
 * role, exactly as for audit_logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->unique();

            // See InventoryTransactionType.
            $table->string('type', 32);

            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('counterpart_warehouse_id')->nullable()
                ->constrained('warehouses')->restrictOnDelete();

            // The business document that caused this movement: a goods receipt,
            // a QC decision, a manufacturing order, an adjustment request.
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->timestamp('transacted_at');
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('type');
            $table->index(['warehouse_id', 'transacted_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('inventory_transaction_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_transaction_id')
                ->constrained('inventory_transactions')->cascadeOnDelete();

            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('inventory_lots')->restrictOnDelete();

            // The warehouse this line affects. A transfer has two lines: one
            // negative at the source, one positive at the destination.
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()
                ->constrained('warehouse_locations')->nullOnDelete();

            // Signed, in the item's stock unit. Positive brings stock in.
            $table->decimal('quantity', 20, 6);
            $table->decimal('unit_cost', 20, 4)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['item_id', 'warehouse_id']);
            $table->index('lot_id');
        });

        // A zero-quantity line is a bug, not a movement.
        DB::statement('ALTER TABLE inventory_transaction_lines ADD CONSTRAINT inventory_transaction_lines_nonzero CHECK (quantity <> 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transaction_lines');
        Schema::dropIfExists('inventory_transactions');
    }
};
