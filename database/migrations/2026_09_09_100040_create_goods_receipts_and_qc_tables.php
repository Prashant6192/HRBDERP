<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Goods receipts and the quality checkpoint that stands between them and
 * the store.
 *
 * A receipt is entered with everything the sticker will need — supplier
 * batch, manufacture and expiry dates — before quality sees it. Posting the
 * receipt creates the lot and puts the stock in quarantine; the inspection
 * decides whether it moves on to the store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->unique();

            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->restrictOnDelete();

            // The store the stock is destined for once released.
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();

            $table->date('received_at');
            $table->string('invoice_ref', 64)->nullable();

            // 'draft' | 'received' | 'cancelled'
            $table->string('status', 16)->default('draft');

            $table->text('notes')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'received_at']);
            $table->index('vendor_id');
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();

            // As entered on the delivery note, in whatever unit it came in.
            $table->decimal('quantity', 20, 6);
            $table->foreignId('uom_id')->constrained('uoms')->restrictOnDelete();

            // The same quantity in the item's stock unit — what the ledger gets.
            $table->decimal('stock_quantity', 20, 6);

            $table->decimal('unit_price', 20, 4)->nullable();

            $table->string('supplier_batch_ref', 128)->nullable();
            $table->date('manufactured_at')->nullable();
            $table->date('expiry_at')->nullable();

            // Filled in when the receipt is posted.
            $table->string('batch_number', 64)->nullable();
            $table->foreignId('lot_id')->nullable()->constrained('inventory_lots')->restrictOnDelete();
            $table->foreignId('qc_inspection_id')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('item_id');
        });

        DB::statement('ALTER TABLE goods_receipt_lines ADD CONSTRAINT goods_receipt_lines_positive CHECK (quantity > 0 AND stock_quantity > 0)');

        Schema::create('qc_inspections', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->unique();

            $table->foreignId('lot_id')->constrained('inventory_lots')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('goods_receipt_line_id')->nullable()
                ->constrained('goods_receipt_lines')->nullOnDelete();

            $table->decimal('quantity', 20, 6);

            // Same vocabulary as the lot: 'pending' | 'approved' | 'rejected' | 'on_hold'
            $table->string('status', 16)->default('pending');

            // Where approved stock is released to.
            $table->foreignId('destination_warehouse_id')->constrained('warehouses')->restrictOnDelete();

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('remarks')->nullable();

            // Free-form test results: pH, viscosity, appearance, whatever the
            // material's specification asks for.
            $table->jsonb('parameters')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('item_id');
        });

        Schema::table('goods_receipt_lines', function (Blueprint $table): void {
            $table->foreign('qc_inspection_id')->references('id')->on('qc_inspections')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_lines', function (Blueprint $table): void {
            $table->dropForeign(['qc_inspection_id']);
        });
        Schema::dropIfExists('qc_inspections');
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
    }
};
