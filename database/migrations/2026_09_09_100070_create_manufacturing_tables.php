<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manufacturing orders: a plan being made.
 *
 * Approval reserves the materials in their stores; starting consumes the
 * raw materials through the ledger; completing consumes the packaging,
 * posts the finished batch as a lot (into quarantine when the product
 * needs QC) and closes the plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manufacturing_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('production_plan_id')->nullable()->constrained('production_plans')->nullOnDelete();
            $table->foreignId('formula_id')->constrained('formulas')->restrictOnDelete();
            $table->foreignId('formula_version_id')->constrained('formula_versions')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('items')->nullOnDelete();

            $table->decimal('planned_quantity', 20, 6);
            $table->foreignId('planned_uom_id')->constrained('uoms')->restrictOnDelete();
            $table->unsignedInteger('planned_units')->nullable();

            $table->string('status', 24)->default('draft')->index();

            // What actually came out.
            $table->decimal('output_quantity', 20, 6)->nullable();
            $table->unsignedInteger('output_units')->nullable();
            $table->decimal('yield_percentage', 8, 3)->nullable();
            $table->foreignId('output_lot_id')->nullable()->constrained('inventory_lots')->nullOnDelete();
            $table->date('manufactured_at')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['production_plan_id', 'status']);
        });

        DB::statement('ALTER TABLE manufacturing_orders ADD CONSTRAINT manufacturing_orders_qty_positive CHECK (planned_quantity > 0)');

        Schema::create('manufacturing_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('manufacturing_order_id')->constrained('manufacturing_orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('store_kind', 24);
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('uoms')->restrictOnDelete();
            $table->decimal('percentage', 12, 6)->nullable();
            $table->boolean('is_qs')->default(false);
            $table->boolean('as_required')->default(false);

            // All in the item's stock unit.
            $table->decimal('planned_quantity', 20, 6)->default('0');
            $table->decimal('reserved_quantity', 20, 6)->default('0');
            $table->decimal('consumed_quantity', 20, 6)->default('0');
            $table->timestamps();

            $table->unique(['manufacturing_order_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manufacturing_order_lines');
        Schema::dropIfExists('manufacturing_orders');
    }
};
