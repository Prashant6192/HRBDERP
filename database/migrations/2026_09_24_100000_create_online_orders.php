<?php

declare(strict_types=1);

use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Online orders: the labels the marketplaces generate every morning, and
 * each parcel's life from upload to the courier's hands.
 *
 *   brands               — Rahat Rooh, Cleanse Ayurveda: whose goods a label
 *                          sells, whose batches it may draw on, and the
 *                          store it ships from by default.
 *   brand_user           — which brands an outside agency may upload for.
 *   marketplaces         — Meesho, Flipkart, Amazon, Myntra, and how their
 *                          labels are read.
 *   marketplace_listings — the SKU text a marketplace prints, mapped once
 *                          to the product it is; remembered from then on.
 *   label_batches        — one day's upload for one brand on one
 *                          marketplace, shipping from one store.
 *   label_files          — the PDFs exactly as the marketplace gave them.
 *                          Never rewritten: printing reassembles pages.
 *   shipments            — one parcel: its pages in the file, the AWB and
 *                          the order, and where it stands (uploaded,
 *                          printed, packed, handed over, cancelled).
 *   shipment_lines       — what goes in it, as printed and as mapped.
 *   label_prints         — who printed what, when.
 *   handover_sheets      — the courier's pickup: how many parcels, signed for.
 *
 * Stock is reserved against the shipment when it is uploaded and leaves
 * through the ledger (MARKETPLACE_SALE) when the parcel is packed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('gstin', 20)->nullable();
            // Whose batches this brand sells: a contract client's, or (null)
            // the company's own.
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            // The finished goods store its parcels leave from unless the
            // upload says otherwise.
            $table->foreignId('default_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('brand_user', function (Blueprint $table): void {
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['brand_id', 'user_id']);
        });

        Schema::create('marketplaces', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 24)->unique();
            $table->string('name');
            // How its labels are read: meesho | flipkart | ai
            $table->string('reader', 24)->default('ai');
            // How long after a return arrives a damage claim can be raised.
            $table->unsignedInteger('claim_window_hours')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('marketplace_listings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketplace_id')->constrained('marketplaces')->restrictOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->restrictOnDelete();
            // As the label prints it, and folded for matching.
            $table->string('seller_sku');
            $table->string('sku_key');
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            // A "pack of 2" listing sends two of the product per order.
            $table->unsignedInteger('units_per_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['marketplace_id', 'brand_id', 'sku_key']);
            $table->index('item_id');
        });

        DB::statement('ALTER TABLE marketplace_listings ADD CONSTRAINT marketplace_listings_units_positive CHECK (units_per_order > 0)');

        Schema::create('label_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('brand_id')->constrained('brands')->restrictOnDelete();
            $table->foreignId('marketplace_id')->constrained('marketplaces')->restrictOnDelete();
            $table->foreignId('facility_id')->constrained('facilities')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('for_date');
            // open → closed (the agency has uploaded everything for the day)
            $table->string('status', 16)->default('open')->index();
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['for_date', 'facility_id']);
            $table->index(['brand_id', 'marketplace_id', 'for_date']);
        });

        Schema::create('label_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('label_batch_id')->constrained('label_batches')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('pages')->default(0);
            // The same file uploaded twice would double every order in it.
            $table->char('sha256', 64)->unique();
            $table->string('read_with', 24)->nullable();
            $table->string('read_model', 64)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->jsonb('warnings')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('handover_sheets', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('facility_id')->constrained('facilities')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('courier', 64);
            $table->unsignedInteger('shipment_count')->default(0);
            // The courier's person who signed for the parcels.
            $table->string('received_by_name')->nullable();
            $table->foreignId('handed_over_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handed_over_at');
            $table->timestamps();

            $table->index(['facility_id', 'handed_over_at']);
        });

        Schema::create('shipments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('label_batch_id')->constrained('label_batches')->restrictOnDelete();
            $table->foreignId('label_file_id')->constrained('label_files')->restrictOnDelete();
            $table->foreignId('marketplace_id')->constrained('marketplaces')->restrictOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            // The pages of the file that make up this parcel's paperwork: the
            // label, and on some marketplaces the invoice after it.
            $table->jsonb('pages');

            $table->string('awb', 64)->nullable();
            // A second code printed on the label (Flipkart's tracking id), so
            // either barcode finds the parcel.
            $table->string('alt_code', 64)->nullable();
            $table->string('order_number', 64)->nullable();
            $table->string('courier', 64)->nullable();
            // cod | prepaid | unknown
            $table->string('payment_mode', 16)->default('unknown');
            $table->decimal('payable_amount', 12, 2)->nullable();
            $table->string('invoice_number', 64)->nullable();
            $table->date('invoice_date')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_state', 64)->nullable();
            $table->string('seller_gstin', 20)->nullable();

            // uploaded → printed → packed → handed_over; or cancelled
            $table->string('status', 16)->default('uploaded')->index();
            // unmapped | reserved | short | consumed | released
            $table->string('stock_state', 16)->default('unmapped');

            $table->timestamp('printed_at')->nullable();
            $table->foreignId('printed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('print_count')->default(0);
            $table->timestamp('packed_at')->nullable();
            $table->foreignId('packed_by')->nullable()->constrained('users')->nullOnDelete();
            // scan | manual — a manual pack carries a reason.
            $table->string('pack_method', 8)->nullable();
            $table->string('pack_note')->nullable();
            $table->timestamp('handed_over_at')->nullable();
            $table->foreignId('handed_over_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('handover_sheet_id')->nullable()->constrained('handover_sheets')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();

            // What the reader made of the page, and anything it was unsure of.
            $table->jsonb('extraction')->nullable();
            $table->jsonb('warnings')->nullable();
            $table->timestamps();

            $table->index(['label_batch_id', 'courier']);
            $table->index(['warehouse_id', 'status']);
            $table->index('awb');
            $table->index('alt_code');
            $table->index('order_number');
        });

        // One parcel per AWB on a marketplace. A cancelled one frees it.
        DB::statement("CREATE UNIQUE INDEX shipments_awb_unique ON shipments (marketplace_id, awb) WHERE awb IS NOT NULL AND status <> 'cancelled'");

        Schema::create('shipment_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('seller_sku');
            $table->string('description')->nullable();
            $table->unsignedInteger('quantity');
            $table->foreignId('listing_id')->nullable()->constrained('marketplace_listings')->nullOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('items')->restrictOnDelete();
            // quantity × units per order, in the product's stock unit.
            $table->decimal('units', 20, 6)->nullable();
            $table->timestamps();

            $table->unique(['shipment_id', 'line_no']);
            $table->index('item_id');
        });

        DB::statement('ALTER TABLE shipment_lines ADD CONSTRAINT shipment_lines_quantity_positive CHECK (quantity > 0)');

        Schema::create('label_prints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('label_batch_id')->constrained('label_batches')->cascadeOnDelete();
            $table->foreignId('printed_by')->nullable()->constrained('users')->nullOnDelete();
            // all | courier | unprinted | one
            $table->string('scope', 16);
            $table->string('courier', 64)->nullable();
            $table->unsignedInteger('shipment_count');
            $table->unsignedInteger('page_count');
            $table->timestamps();
        });

        // The marketplaces and brands are reference data: present from the
        // moment the tables are, whether or not db:seed runs.
        ReferenceDataSeeder::ensureMarketplaces();
        ReferenceDataSeeder::ensureBrands();
    }

    public function down(): void
    {
        Schema::dropIfExists('label_prints');
        Schema::dropIfExists('shipment_lines');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('handover_sheets');
        Schema::dropIfExists('label_files');
        Schema::dropIfExists('label_batches');
        Schema::dropIfExists('marketplace_listings');
        Schema::dropIfExists('marketplaces');
        Schema::dropIfExists('brand_user');
        Schema::dropIfExists('brands');
    }
};
