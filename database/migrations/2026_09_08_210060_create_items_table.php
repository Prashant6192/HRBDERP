<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every stockable thing in the business lives in this one table.
 *
 * Raw materials, packaging materials and finished goods are presented as three
 * separate modules in the interface — they are maintained by different people
 * and carry different fields — but they share a table because they share a
 * life: the same inventory ledger moves all of them, the same purchase order
 * can buy any of them, and a finished good is consumed as an input by the next
 * production order up the chain. Splitting them into three tables would mean
 * three of every ledger, reservation and costing relation.
 *
 * The 'type' column discriminates, and the RawMaterial, PackagingMaterial and
 * Product models each apply a global scope for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table): void {
            $table->id();

            $table->string('code', 64)->unique();
            $table->string('name');

            // 'raw_material' | 'packaging_material' | 'finished_good'
            // | 'semi_finished' | 'consumable'
            $table->string('type', 32);

            $table->foreignId('category_id')->nullable()
                ->constrained('item_categories')->nullOnDelete();

            $table->text('description')->nullable();

            // ---- Units -----------------------------------------------------
            // Stock for this item is held, and its ledger written, in this
            // unit. Receipts and issues in any other unit are converted to it
            // on the way in.
            $table->foreignId('stock_uom_id')->constrained('uoms')->restrictOnDelete();

            // The unit this item is normally bought in, when it differs.
            $table->foreignId('purchase_uom_id')->nullable()
                ->constrained('uoms')->restrictOnDelete();

            // Grams per millilitre. Present only where a material is bought by
            // weight and dosed by volume, or the reverse; it is the only
            // sanctioned bridge between the mass and volume dimensions.
            $table->decimal('density_g_per_ml', 16, 6)->nullable();

            // ---- Commercial ------------------------------------------------
            $table->string('hsn_code', 16)->nullable();
            $table->decimal('gst_rate', 6, 3)->nullable();

            // Latest standard cost per stock unit. Historical costs live with
            // the batch that used them and are never rewritten from here.
            $table->decimal('standard_cost', 20, 4)->nullable();

            // Finished goods only.
            $table->string('brand', 128)->nullable();
            $table->decimal('mrp', 20, 4)->nullable();
            $table->decimal('net_content', 20, 6)->nullable();
            $table->foreignId('net_content_uom_id')->nullable()
                ->constrained('uoms')->restrictOnDelete();
            $table->string('barcode', 64)->nullable();

            // ---- Stock control ---------------------------------------------
            $table->boolean('is_batch_tracked')->default(true);
            $table->boolean('requires_qc')->default(false);
            $table->unsignedInteger('shelf_life_days')->nullable();

            $table->decimal('reorder_level', 20, 6)->nullable();
            $table->decimal('minimum_stock', 20, 6)->nullable();
            $table->decimal('maximum_stock', 20, 6)->nullable();
            $table->unsignedInteger('lead_time_days')->nullable();

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'is_active']);
            $table->index('name');
            $table->index('barcode');
        });

        // Stock-control levels describe quantities; a negative one is always a
        // data-entry error rather than a meaningful instruction.
        DB::statement(<<<'SQL'
            ALTER TABLE items ADD CONSTRAINT items_non_negative_levels CHECK (
                (reorder_level  IS NULL OR reorder_level  >= 0) AND
                (minimum_stock  IS NULL OR minimum_stock  >= 0) AND
                (maximum_stock  IS NULL OR maximum_stock  >= 0) AND
                (standard_cost  IS NULL OR standard_cost  >= 0) AND
                (mrp            IS NULL OR mrp            >= 0) AND
                (density_g_per_ml IS NULL OR density_g_per_ml > 0)
            )
        SQL);

        Schema::create('item_uom_conversions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('from_uom_id')->constrained('uoms')->restrictOnDelete();
            $table->foreignId('to_uom_id')->constrained('uoms')->restrictOnDelete();

            // How many 'to' units make one 'from' unit, for this item alone.
            // Used where the factor belongs to the packaging rather than to the
            // unit: one carton of this particular bottle holds 24 pieces.
            $table->decimal('factor', 30, 12);

            $table->timestamps();

            $table->unique(['item_id', 'from_uom_id', 'to_uom_id']);
        });

        DB::statement(
            'ALTER TABLE item_uom_conversions ADD CONSTRAINT item_uom_conversions_factor_positive CHECK (factor > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('item_uom_conversions');
        Schema::dropIfExists('items');
    }
};
