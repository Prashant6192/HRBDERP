<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A pack of two (or more) of a product, kept on the shelf and sold as one:
 * "Rahat Rooh Medicated Oil 500ml (Pack of 2)" is its own product, made of
 * two of the single. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->foreignId('pack_of_item_id')->nullable()->after('units_per_carton')->constrained('items')->nullOnDelete();
            $table->unsignedSmallInteger('pack_quantity')->nullable()->after('pack_of_item_id');
            $table->index(['pack_of_item_id', 'pack_quantity']);
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->dropIndex(['pack_of_item_id', 'pack_quantity']);
            $table->dropConstrainedForeignId('pack_of_item_id');
            $table->dropColumn('pack_quantity');
        });
    }
};
