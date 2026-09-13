<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Third-party / contract manufacturing: the clients the company makes for,
 * their artwork approvals and their QC specifications. Everything else
 * about a client's job lives on the ordinary planning, manufacturing and
 * inventory tables, tagged with the client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('gstin', 20)->nullable();
            $table->string('pan', 16)->nullable();
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
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->decimal('credit_limit', 20, 2)->nullable();
            $table->string('agreement_ref', 128)->nullable();
            $table->date('agreement_expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
            $table->index('gstin');
        });

        Schema::create('client_artworks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('items')->nullOnDelete();
            $table->string('kind', 16);
            $table->string('title');
            $table->string('version', 32);
            $table->string('status', 16)->default('pending')->index();
            $table->date('approved_at')->nullable();
            $table->string('approved_by_name')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('document_path')->nullable();
            $table->string('document_name')->nullable();
            $table->string('document_mime', 64)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'product_id', 'status']);
        });

        Schema::create('client_qc_specs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('items')->cascadeOnDelete();
            $table->jsonb('parameters');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['client_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_qc_specs');
        Schema::dropIfExists('client_artworks');
        Schema::dropIfExists('clients');
    }
};
