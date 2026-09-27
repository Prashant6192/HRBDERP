<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a carton sticker needs that the records did not hold yet: the
 * factory's cosmetics manufacturing licence, each brand's "Marketed by"
 * line and consumer-care contact, and how many pieces a product's carton
 * holds (remembered from the last carton plan). Plus a log of every
 * sticker print: who, when, which printer, which cartons.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->string('manufacturing_licence', 64)->nullable()->after('gstin');
        });

        Schema::table('brands', function (Blueprint $table) {
            $table->string('marketed_by', 500)->nullable()->after('gstin');
            $table->string('consumer_care', 255)->nullable()->after('marketed_by');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->unsignedInteger('units_per_carton')->nullable()->after('net_content_uom_id');
        });

        Schema::create('carton_label_prints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lot_id')->constrained('inventory_lots')->cascadeOnDelete();
            $table->string('format', 24);
            $table->string('printer', 160)->nullable();
            $table->unsignedInteger('first_box');
            $table->unsignedInteger('last_box');
            $table->unsignedSmallInteger('copies')->default(1);
            $table->foreignId('printed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('printed_at');
            $table->timestamps();

            $table->index(['lot_id', 'printed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carton_label_prints');

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('units_per_carton');
        });

        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn(['marketed_by', 'consumer_care']);
        });

        Schema::table('facilities', function (Blueprint $table) {
            $table->dropColumn('manufacturing_licence');
        });
    }
};
