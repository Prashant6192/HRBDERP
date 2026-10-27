<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where on its page a parcel's label sits, when a page holds several
 * (Shiprocket prints two to an A4 page): 0 is the first, left to right.
 * Empty for a label that has its page to itself. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->unsignedTinyInteger('page_slot')->nullable()->after('pages');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropColumn('page_slot');
        });
    }
};
