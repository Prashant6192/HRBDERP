<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dispatch: finished goods leaving a facility against a tax invoice.
 *
 *   customers            — who is billed and who receives: a contract
 *                          client, a marketplace, a distributor.
 *   dispatches           — one consignment: from which finished goods store,
 *                          to whom, under which invoice, with the e-invoice
 *                          particulars (IRN, acknowledgement, signed QR) and
 *                          the transport it left on.
 *   dispatch_lines       — what went, batch by batch, with HSN, rate and
 *                          the GST split exactly as the invoice carries it.
 *   dispatch_attachments — the invoice, the signed e-invoice, the e-way
 *                          bill, the LR: every paper that travelled with it.
 *
 * The stock itself leaves through the inventory ledger (SALES_DISPATCH),
 * posted by DispatchService when the goods are dispatched, never before.
 *
 * Facilities gain a legal name: the entity that bills for goods leaving
 * that site (the factory is one company; the depot may be another).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facilities', function (Blueprint $table): void {
            $table->string('legal_name')->nullable()->after('name');
        });

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('gstin', 20)->nullable();
            $table->string('pan', 10)->nullable();
            // client | marketplace | distributor | retailer | other
            $table->string('kind', 24)->default('other')->index();
            // A contract client billed for their own goods.
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('contact_person')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('billing_address_line_1')->nullable();
            $table->string('billing_address_line_2')->nullable();
            $table->string('billing_city', 128)->nullable();
            $table->string('billing_state', 128)->nullable();
            $table->string('billing_pincode', 16)->nullable();
            $table->string('shipping_address_line_1')->nullable();
            $table->string('shipping_address_line_2')->nullable();
            $table->string('shipping_city', 128)->nullable();
            $table->string('shipping_state', 128)->nullable();
            $table->string('shipping_pincode', 16)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // One GSTIN is one legal entity.
        DB::statement('CREATE UNIQUE INDEX customers_gstin_unique ON customers (gstin) WHERE gstin IS NOT NULL AND deleted_at IS NULL');

        Schema::create('dispatches', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('facility_id')->constrained('facilities')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            // draft → invoiced → dispatched → delivered; or cancelled
            $table->string('status', 24)->default('draft')->index();
            $table->string('reference', 64)->nullable();

            // The invoice, as the billing software raised it.
            $table->string('invoice_number', 64)->nullable();
            $table->date('invoice_date')->nullable();
            $table->char('place_of_supply', 2)->nullable();
            $table->boolean('is_interstate')->default(false);

            // Where it goes, frozen at the time: a customer's address can
            // change later, the consignment's cannot.
            $table->string('ship_to_name')->nullable();
            $table->string('ship_to_gstin', 20)->nullable();
            $table->string('ship_to_address_line_1')->nullable();
            $table->string('ship_to_address_line_2')->nullable();
            $table->string('ship_to_city', 128)->nullable();
            $table->string('ship_to_state', 128)->nullable();
            $table->string('ship_to_pincode', 16)->nullable();

            // The e-invoice: what the IRP handed back.
            $table->string('irn', 64)->nullable();
            $table->string('ack_number', 32)->nullable();
            $table->timestamp('ack_date')->nullable();
            $table->text('signed_qr')->nullable();

            // Transport, filled in as it leaves.
            $table->string('transporter_name')->nullable();
            $table->string('transporter_gstin', 20)->nullable();
            $table->string('vehicle_number', 32)->nullable();
            $table->string('lr_number', 64)->nullable();
            $table->date('lr_date')->nullable();
            $table->string('eway_bill_number', 16)->nullable();
            $table->date('eway_bill_date')->nullable();
            $table->unsignedInteger('distance_km')->nullable();

            // Money, in rupees to the paisa.
            $table->decimal('taxable_value', 20, 2)->default('0');
            $table->decimal('cgst', 20, 2)->default('0');
            $table->decimal('sgst', 20, 2)->default('0');
            $table->decimal('igst', 20, 2)->default('0');
            $table->decimal('other_charges', 20, 2)->default('0');
            $table->decimal('round_off', 20, 2)->default('0');
            $table->decimal('total_value', 20, 2)->default('0');

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('invoiced_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('invoiced_at')->nullable();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dispatched_at')->nullable();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->string('delivery_note')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['facility_id', 'status']);
            $table->index(['customer_id', 'status']);
            $table->index('dispatched_at');
            $table->index('invoice_date');
        });

        // An invoice number is issued once; an IRN is unique by construction.
        DB::statement('CREATE UNIQUE INDEX dispatches_invoice_number_unique ON dispatches (invoice_number) WHERE invoice_number IS NOT NULL AND deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX dispatches_irn_unique ON dispatches (irn) WHERE irn IS NOT NULL AND deleted_at IS NULL');

        Schema::create('dispatch_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dispatch_id')->constrained('dispatches')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('lot_id')->constrained('inventory_lots')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('uoms')->restrictOnDelete();
            $table->decimal('quantity', 20, 6);
            $table->decimal('unit_price', 20, 4);
            $table->decimal('discount_percent', 6, 3)->default('0');
            $table->string('hsn_code', 16)->nullable();
            $table->decimal('gst_rate', 6, 3)->default('0');
            $table->decimal('taxable_value', 20, 2)->default('0');
            $table->decimal('cgst', 20, 2)->default('0');
            $table->decimal('sgst', 20, 2)->default('0');
            $table->decimal('igst', 20, 2)->default('0');
            $table->decimal('line_total', 20, 2)->default('0');
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['dispatch_id', 'line_no']);
            $table->index(['lot_id']);
        });

        DB::statement('ALTER TABLE dispatch_lines ADD CONSTRAINT dispatch_lines_quantity_positive CHECK (quantity > 0)');
        DB::statement('ALTER TABLE dispatch_lines ADD CONSTRAINT dispatch_lines_money_sane CHECK (unit_price >= 0 AND discount_percent >= 0 AND discount_percent <= 100 AND gst_rate >= 0 AND gst_rate <= 100)');

        Schema::create('dispatch_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dispatch_id')->constrained('dispatches')->cascadeOnDelete();
            // invoice | signed_invoice | eway_bill | lr | other
            $table->string('kind', 24)->index();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 128)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_attachments');
        Schema::dropIfExists('dispatch_lines');
        Schema::dropIfExists('dispatches');
        Schema::dropIfExists('customers');

        Schema::table('facilities', function (Blueprint $table): void {
            $table->dropColumn('legal_name');
        });
    }
};
