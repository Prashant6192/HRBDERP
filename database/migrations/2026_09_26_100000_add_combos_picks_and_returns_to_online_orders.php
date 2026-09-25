<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Online orders, second part:
 *
 * - A marketplace SKU can be a combo: several products, each with its
 *   pieces ("RR 500 ml + Satreetha", "pack of 2").
 * - Each parcel carries a pick list, product by product, that the stock
 *   is held and taken against.
 * - Returns come back against the parcel they left in: good goods to the
 *   shelf, damaged goods to the damaged store, with a claim if owed.
 *
 * Everything here adds; nothing existing is dropped. Listings and parcels
 * already on file are carried over as one-product combos and pick lists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_listing_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('listing_id')->constrained('marketplace_listings')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            // Pieces of this product in one order of the listing.
            $table->unsignedInteger('units_per_order')->default(1);
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->timestamps();

            $table->unique(['listing_id', 'item_id']);
            $table->index('item_id');
        });

        DB::statement('ALTER TABLE marketplace_listing_components ADD CONSTRAINT listing_components_units_positive CHECK (units_per_order > 0)');

        DB::statement(<<<'SQL'
            INSERT INTO marketplace_listing_components (listing_id, item_id, units_per_order, line_no, created_at, updated_at)
            SELECT id, item_id, units_per_order, 1, created_at, updated_at FROM marketplace_listings
        SQL);

        Schema::create('shipment_picks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->foreignId('shipment_line_id')->constrained('shipment_lines')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            // In the product's stock unit: label quantity × pieces per order.
            $table->decimal('units', 20, 6);
            $table->timestamps();

            $table->index('shipment_id');
            $table->index('item_id');
        });

        DB::statement('ALTER TABLE shipment_picks ADD CONSTRAINT shipment_picks_units_positive CHECK (units > 0)');

        DB::statement(<<<'SQL'
            INSERT INTO shipment_picks (shipment_id, shipment_line_id, item_id, units, created_at, updated_at)
            SELECT shipment_id, id, item_id, units, created_at, updated_at
            FROM shipment_lines
            WHERE item_id IS NOT NULL AND units IS NOT NULL AND units > 0
        SQL);

        Schema::table('shipments', function (Blueprint $table): void {
            $table->timestamp('returned_at')->nullable()->after('cancel_reason');
        });

        Schema::create('shipment_returns', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('shipment_id')->constrained('shipments')->restrictOnDelete();
            $table->foreignId('marketplace_id')->constrained('marketplaces')->restrictOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->restrictOnDelete();
            $table->foreignId('facility_id')->constrained('facilities')->restrictOnDelete();
            // rto (never delivered) | customer (delivered, sent back)
            $table->string('kind', 16);
            // A code printed on the returning packet, when it differs.
            $table->string('return_awb', 64)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('wrong_item')->default(false);
            // none | open | won | lost
            $table->string('claim_status', 8)->default('none')->index();
            $table->timestamp('claim_deadline_at')->nullable();
            $table->string('claim_reference', 64)->nullable();
            $table->decimal('claim_amount', 12, 2)->nullable();
            $table->string('claim_note')->nullable();
            $table->foreignId('inventory_transaction_id')->nullable()->constrained('inventory_transactions')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['facility_id', 'received_at']);
            $table->unique('shipment_id');
        });

        Schema::create('shipment_return_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_return_id')->constrained('shipment_returns')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            // What left in the parcel, and what came back of it.
            $table->decimal('sent', 20, 6);
            $table->decimal('good', 20, 6)->default(0);
            $table->decimal('damaged', 20, 6)->default(0);
            $table->decimal('missing', 20, 6)->default(0);
            $table->foreignId('good_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('damaged_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->timestamps();

            $table->index('item_id');
        });

        DB::statement('ALTER TABLE shipment_return_lines ADD CONSTRAINT shipment_return_lines_sane CHECK (good >= 0 AND damaged >= 0 AND missing >= 0 AND good + damaged + missing = sent)');
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_return_lines');
        Schema::dropIfExists('shipment_returns');

        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropColumn('returned_at');
        });

        Schema::dropIfExists('shipment_picks');
        Schema::dropIfExists('marketplace_listing_components');
    }
};
