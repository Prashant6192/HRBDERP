<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();

            $table->string('gstin', 20)->nullable();
            $table->string('pan', 16)->nullable();

            $table->string('contact_person')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();

            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city', 128)->nullable();
            $table->string('state', 128)->nullable();
            $table->string('pincode', 16)->nullable();
            $table->string('country', 128)->default('India');

            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->decimal('credit_limit', 20, 4)->nullable();

            // 'raw_material' | 'packaging' | 'services' | 'mixed'
            $table->string('supply_type', 32)->default('mixed');

            $table->boolean('is_approved')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'is_approved']);
            $table->index('name');
        });

        // A GSTIN identifies one legal entity; two vendor records sharing one
        // is a duplicate rather than a second supplier. Enforced only over
        // live rows so a soft-deleted vendor does not block re-registration.
        DB::statement(
            'CREATE UNIQUE INDEX vendors_gstin_unique ON vendors (gstin) WHERE gstin IS NOT NULL AND deleted_at IS NULL'
        );

        DB::statement(
            'ALTER TABLE vendors ADD CONSTRAINT vendors_credit_limit_non_negative CHECK (credit_limit IS NULL OR credit_limit >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
