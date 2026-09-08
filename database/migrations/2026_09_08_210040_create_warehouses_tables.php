<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');

            // 'raw_material' | 'packaging' | 'finished_goods' | 'quarantine'
            // | 'marketplace' | 'general'
            $table->string('type', 32)->default('general');

            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city', 128)->nullable();
            $table->string('state', 128)->nullable();
            $table->string('pincode', 16)->nullable();
            $table->string('country', 128)->default('India');
            $table->string('gstin', 20)->nullable();

            // Stock in a quarantine warehouse is on the books but not
            // available to production or sales until QC releases it.
            $table->boolean('is_quarantine')->default(false);

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'is_active']);
        });

        Schema::create('warehouse_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();

            $table->string('code', 32);
            $table->string('name');

            // 'rack' | 'bin' | 'floor' | 'staging' | 'quarantine' | 'cold_room'
            $table->string('type', 32)->default('rack');

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // A location code is unique within its warehouse, not globally:
            // every warehouse is entitled to a rack called A-01.
            $table->unique(['warehouse_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_locations');
        Schema::dropIfExists('warehouses');
    }
};
