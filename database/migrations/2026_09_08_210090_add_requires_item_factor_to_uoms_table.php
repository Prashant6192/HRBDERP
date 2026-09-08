<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks units whose size is a property of the item rather than of the unit.
 *
 * "One carton" is not a quantity. A carton of 200ml bottles holds 24; a carton
 * of caps holds 5,000. Such a unit therefore cannot carry a meaningful global
 * factor, and any conversion involving it is only valid when the item supplies
 * its own factor.
 *
 * Without this flag those units would need a global factor anyway — almost
 * certainly 1 — and a carton would quietly convert to a single piece. Flagging
 * them lets UnitConversionService refuse the conversion instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uoms', function (Blueprint $table): void {
            $table->boolean('requires_item_factor')->default(false)->after('is_base');
        });
    }

    public function down(): void
    {
        Schema::table('uoms', function (Blueprint $table): void {
            $table->dropColumn('requires_item_factor');
        });
    }
};
