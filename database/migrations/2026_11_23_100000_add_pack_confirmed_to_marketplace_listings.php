<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Someone has said that one unit of this SKU really is several pieces (a
 * pack of two, say), so SKU mapping stops asking them to check it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_listings', function (Blueprint $table): void {
            $table->boolean('pack_confirmed')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_listings', function (Blueprint $table): void {
            $table->dropColumn('pack_confirmed');
        });
    }
};
