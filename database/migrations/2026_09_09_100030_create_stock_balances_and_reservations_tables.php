<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * The cached balance for one item in one warehouse, per lot.
         *
         * This row is what gets locked. InventoryLedgerService takes it
         * FOR UPDATE before applying a line, so two transactions touching the
         * same stock are serialised by the database rather than by hope, and
         * the CHECK constraints make a negative balance impossible to commit
         * even if the application logic were wrong.
         */
        Schema::create('stock_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('inventory_lots')->restrictOnDelete();

            $table->decimal('on_hand', 20, 6)->default(0);
            $table->decimal('reserved', 20, 6)->default(0);

            $table->timestamps();

            $table->index(['warehouse_id', 'item_id']);
        });

        // One balance row per (item, warehouse, lot), treating "no lot" as a
        // value rather than as a wildcard — PostgreSQL 15+ syntax.
        DB::statement('CREATE UNIQUE INDEX stock_balances_unique ON stock_balances (item_id, warehouse_id, lot_id) NULLS NOT DISTINCT');

        DB::statement(<<<'SQL'
            ALTER TABLE stock_balances ADD CONSTRAINT stock_balances_sane CHECK (
                on_hand >= 0 AND reserved >= 0 AND reserved <= on_hand
            )
        SQL);

        /*
         * Stock held for something, without having left the shelf.
         *
         * Approving a manufacturing order reserves its materials; starting it
         * consumes them; cancelling it releases them. Physical stock is
         * unchanged by a reservation — only what is available to the next
         * person asking.
         */
        Schema::create('stock_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('inventory_lots')->restrictOnDelete();

            $table->decimal('quantity', 20, 6);
            $table->decimal('consumed_quantity', 20, 6)->default(0);

            // What the stock is held for — a manufacturing order, usually.
            $table->string('reservable_type');
            $table->unsignedBigInteger('reservable_id');

            // 'active' | 'consumed' | 'released'
            $table->string('status', 16)->default('active');

            $table->foreignId('reserved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reserved_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['reservable_type', 'reservable_id']);
            $table->index(['item_id', 'warehouse_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_sane CHECK (
                quantity > 0 AND consumed_quantity >= 0 AND consumed_quantity <= quantity
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('stock_balances');
    }
};
