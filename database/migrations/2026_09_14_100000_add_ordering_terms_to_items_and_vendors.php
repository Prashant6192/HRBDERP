<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the purchase advice needs to turn "buy 43 KG" into an order a
 * supplier will accept: the smallest quantity they sell, the pack it comes
 * in, and how long they take.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->decimal('min_order_quantity', 20, 6)->nullable()->after('lead_time_days');
            $table->decimal('order_multiple', 20, 6)->nullable()->after('min_order_quantity');
        });

        Schema::table('vendors', function (Blueprint $table): void {
            $table->unsignedSmallInteger('lead_time_days')->nullable()->after('payment_terms_days');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->dropColumn(['min_order_quantity', 'order_multiple']);
        });

        Schema::table('vendors', function (Blueprint $table): void {
            $table->dropColumn('lead_time_days');
        });
    }
};
