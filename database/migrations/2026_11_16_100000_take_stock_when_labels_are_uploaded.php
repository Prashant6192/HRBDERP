<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A depot whose parcels are not scanned one by one at packing takes each
 * parcel's stock off the shelf when its label is uploaded; what did not
 * ship is scanned back in at the week's end. Facilities already shipping
 * online orders work this way from now on.
 *
 * Each label's selling price per piece, and the parcel's shipping charge,
 * are kept apart from the goods' value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facilities', function (Blueprint $table): void {
            $table->boolean('takes_stock_on_upload')->default(false)->after('can_return');
        });

        Schema::table('shipment_lines', function (Blueprint $table): void {
            $table->decimal('unit_price', 12, 2)->nullable()->after('quantity');
        });

        Schema::table('shipments', function (Blueprint $table): void {
            $table->decimal('shipping_charge', 12, 2)->nullable()->after('payable_amount');
        });

        DB::table('facilities')
            ->whereIn('id', DB::table('label_batches')->select('facility_id')->whereNotNull('facility_id'))
            ->update(['takes_stock_on_upload' => true]);
    }

    public function down(): void
    {
        Schema::table('shipments', fn (Blueprint $table) => $table->dropColumn('shipping_charge'));
        Schema::table('shipment_lines', fn (Blueprint $table) => $table->dropColumn('unit_price'));
        Schema::table('facilities', fn (Blueprint $table) => $table->dropColumn('takes_stock_on_upload'));
    }
};
