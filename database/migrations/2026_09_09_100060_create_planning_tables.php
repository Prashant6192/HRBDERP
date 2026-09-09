<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Planning & Purchase.
 *
 * A production plan names a formula and a quantity. Checking it works the
 * recipe out against the raw material store and the product's packaging
 * against the packaging store, line by line. Generating requests turns the
 * check into one Production Material Request (PMR) per store, which the
 * store picks against and purchase orders against.
 */
return new class extends Migration
{
    public function up(): void
    {
        // What each finished product is packed in, per unit sold.
        Schema::create('product_packaging_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('packaging_material_id')->constrained('items')->restrictOnDelete();
            $table->decimal('quantity_per_unit', 20, 6);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'packaging_material_id']);
        });

        DB::statement('ALTER TABLE product_packaging_lines ADD CONSTRAINT product_packaging_lines_qty_positive CHECK (quantity_per_unit > 0)');

        Schema::create('production_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('formula_id')->constrained('formulas')->restrictOnDelete();
            $table->foreignId('formula_version_id')->constrained('formula_versions')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('items')->nullOnDelete();
            $table->decimal('planned_quantity', 20, 6);
            $table->foreignId('planned_uom_id')->constrained('uoms')->restrictOnDelete();

            // Units of finished product the batch fills, when the product's
            // net content is known.
            $table->unsignedInteger('planned_units')->nullable();

            $table->string('status', 24)->default('draft')->index();
            $table->date('planned_start_date')->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('warnings')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement('ALTER TABLE production_plans ADD CONSTRAINT production_plans_qty_positive CHECK (planned_quantity > 0)');

        Schema::create('production_plan_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_plan_id')->constrained('production_plans')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('store_kind', 24);
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('uoms')->restrictOnDelete();

            $table->decimal('percentage', 12, 6)->nullable();
            $table->boolean('is_qs')->default(false);
            $table->boolean('as_required')->default(false);

            // All in the item's stock unit, as at checked_at.
            $table->decimal('required_quantity', 20, 6)->default('0');
            $table->decimal('available_quantity', 20, 6)->default('0');
            $table->decimal('shortage_quantity', 20, 6)->default('0');
            $table->decimal('restock_quantity', 20, 6)->default('0');

            $table->string('level_now', 16);
            $table->string('level_after', 16);
            $table->jsonb('notes')->nullable();
            $table->timestamps();

            $table->unique(['production_plan_id', 'item_id']);
        });

        Schema::create('material_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('production_plan_id')->constrained('production_plans')->cascadeOnDelete();
            $table->string('store_kind', 24);
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 24)->default('open')->index();
            $table->date('needed_by')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['production_plan_id', 'store_kind']);
        });

        Schema::create('material_request_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('material_request_id')->constrained('material_requests')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('uoms')->restrictOnDelete();

            $table->decimal('required_quantity', 20, 6);
            $table->decimal('available_quantity', 20, 6)->default('0');
            $table->decimal('quantity_to_order', 20, 6)->default('0');
            $table->decimal('restock_quantity', 20, 6)->default('0');
            $table->decimal('received_quantity', 20, 6)->default('0');
            $table->string('alert_level', 16);
            $table->timestamps();

            $table->unique(['material_request_id', 'item_id']);
        });

        Schema::table('goods_receipts', function (Blueprint $table): void {
            // A delivery booked in against a PMR closes its lines as it lands.
            $table->foreignId('material_request_id')->nullable()->after('vendor_id')
                ->constrained('material_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('material_request_id');
        });

        Schema::dropIfExists('material_request_lines');
        Schema::dropIfExists('material_requests');
        Schema::dropIfExists('production_plan_lines');
        Schema::dropIfExists('production_plans');
        Schema::dropIfExists('product_packaging_lines');
    }
};
