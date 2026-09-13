<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The client runs through the ordinary workflow as a tag: on the product
 * (made for whom), the formula (owned by whom), the plan and the order
 * (whose batch, against which PO, whose material), the goods receipt and
 * every batch in stock (owned by whom). Nothing is duplicated: an own-brand
 * batch simply carries no client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            // "Manufactured for": null is the company's own brand.
            $table->foreignId('client_id')->nullable()->after('brand')->constrained('clients')->nullOnDelete();
        });

        Schema::table('formulas', function (Blueprint $table): void {
            $table->string('ownership', 12)->default('company')->after('status');
            $table->foreignId('client_id')->nullable()->after('ownership')->constrained('clients')->nullOnDelete();
        });

        Schema::table('inventory_lots', function (Blueprint $table): void {
            // Stock the client owns while it sits in our store: material they
            // supplied, or finished goods made for them. Null is ours.
            $table->foreignId('owner_client_id')->nullable()->after('vendor_id')->constrained('clients')->nullOnDelete();
            $table->index(['item_id', 'owner_client_id']);
        });

        Schema::table('goods_receipts', function (Blueprint $table): void {
            $table->foreignId('owner_client_id')->nullable()->after('vendor_id')->constrained('clients')->nullOnDelete();
        });

        Schema::table('production_plans', function (Blueprint $table): void {
            $table->string('manufacturing_type', 16)->default('own')->after('product_id');
            $table->foreignId('client_id')->nullable()->after('manufacturing_type')->constrained('clients')->nullOnDelete();
            $table->string('client_po_ref', 64)->nullable()->after('client_id');
            $table->string('client_product_name')->nullable()->after('client_po_ref');
            $table->date('required_delivery_at')->nullable()->after('client_product_name');
            $table->string('material_source', 12)->default('company')->after('required_delivery_at');
            $table->jsonb('client_supplied_item_ids')->nullable()->after('material_source');

            $table->index(['client_id', 'status']);
        });

        Schema::table('production_plan_lines', function (Blueprint $table): void {
            $table->string('source', 12)->default('company')->after('store_kind');
        });

        Schema::table('material_request_lines', function (Blueprint $table): void {
            $table->string('source', 12)->default('company')->after('line_no');
        });

        Schema::table('manufacturing_orders', function (Blueprint $table): void {
            $table->string('manufacturing_type', 16)->default('own')->after('product_id');
            $table->foreignId('client_id')->nullable()->after('manufacturing_type')->constrained('clients')->nullOnDelete();
            $table->string('client_po_ref', 64)->nullable()->after('client_id');
            $table->date('required_delivery_at')->nullable()->after('client_po_ref');
            $table->string('material_source', 12)->default('company')->after('required_delivery_at');
            $table->jsonb('client_supplied_item_ids')->nullable()->after('material_source');
            // The commercial terms of a third-party job: what is charged.
            $table->jsonb('charges')->nullable()->after('client_supplied_item_ids');

            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('manufacturing_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn(['manufacturing_type', 'client_po_ref', 'required_delivery_at', 'material_source', 'client_supplied_item_ids', 'charges']);
        });
        Schema::table('material_request_lines', fn (Blueprint $table) => $table->dropColumn('source'));
        Schema::table('production_plan_lines', fn (Blueprint $table) => $table->dropColumn('source'));
        Schema::table('production_plans', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn(['manufacturing_type', 'client_po_ref', 'client_product_name', 'required_delivery_at', 'material_source', 'client_supplied_item_ids']);
        });
        Schema::table('goods_receipts', fn (Blueprint $table) => $table->dropConstrainedForeignId('owner_client_id'));
        Schema::table('inventory_lots', function (Blueprint $table): void {
            $table->dropIndex(['item_id', 'owner_client_id']);
            $table->dropConstrainedForeignId('owner_client_id');
        });
        Schema::table('formulas', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn('ownership');
        });
        Schema::table('items', fn (Blueprint $table) => $table->dropConstrainedForeignId('client_id'));
    }
};
