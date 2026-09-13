<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Real-time batch progress, the returns and wastage a batch records, and
 * each manufacturing facility's daily capacity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manufacturing_orders', function (Blueprint $table): void {
            // The stage the batch is at now and how far through it is; the
            // full history is in the events below.
            $table->string('current_stage', 32)->nullable()->after('status');
            $table->unsignedSmallInteger('stage_progress')->default(0)->after('current_stage');
            $table->timestamp('stage_updated_at')->nullable()->after('stage_progress');
        });

        Schema::create('manufacturing_order_stage_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('manufacturing_order_id')->constrained('manufacturing_orders')->cascadeOnDelete();
            $table->string('stage', 32);
            $table->unsignedSmallInteger('progress');
            $table->string('note', 500)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['manufacturing_order_id', 'recorded_at']);
        });

        DB::statement('ALTER TABLE manufacturing_order_stage_events ADD CONSTRAINT mo_stage_events_progress_range CHECK (progress BETWEEN 0 AND 100)');

        Schema::create('manufacturing_order_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('manufacturing_order_id')->constrained('manufacturing_orders')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('inventory_lots')->nullOnDelete();
            // return: material issued but not used, back to the store.
            // wastage: material issued and lost — spilled, scrapped, sampled.
            $table->string('kind', 16);
            $table->decimal('quantity', 20, 6);
            $table->string('reason', 500)->nullable();
            $table->foreignId('inventory_transaction_id')->nullable()->constrained('inventory_transactions')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['manufacturing_order_id', 'item_id']);
        });

        DB::statement('ALTER TABLE manufacturing_order_adjustments ADD CONSTRAINT mo_adjustments_qty_positive CHECK (quantity > 0)');
        DB::statement("ALTER TABLE manufacturing_order_adjustments ADD CONSTRAINT mo_adjustments_kind CHECK (kind IN ('return', 'wastage'))");

        Schema::table('facilities', function (Blueprint $table): void {
            // How much the plant can make in a day, in kilograms of bulk
            // product. Capacity planning divides bookings by it.
            $table->decimal('daily_capacity_kg', 20, 3)->nullable()->after('can_return');
        });
    }

    public function down(): void
    {
        Schema::table('facilities', fn (Blueprint $table) => $table->dropColumn('daily_capacity_kg'));
        Schema::dropIfExists('manufacturing_order_adjustments');
        Schema::dropIfExists('manufacturing_order_stage_events');
        Schema::table('manufacturing_orders', fn (Blueprint $table) => $table->dropColumn(['current_stage', 'stage_progress', 'stage_updated_at']));
    }
};
