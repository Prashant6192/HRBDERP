<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The day the customer placed the order, as the label or invoice prints it
 * ("Order Date: 19-11-2026"). Not every platform prints one; the invoice
 * date and the day the labels were for stand in where it is missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->date('order_date')->nullable()->after('order_number');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropColumn('order_date');
        });
    }
};
