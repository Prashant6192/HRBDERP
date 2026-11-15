<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A count written in a register is entered later than it was taken. The
 * count remembers when the shelf was counted, so what moved since (parcels
 * packed, stock scanned in) is not mistaken for a difference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_counts', function (Blueprint $table): void {
            $table->timestamp('counted_as_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('stock_counts', function (Blueprint $table): void {
            $table->dropColumn('counted_as_at');
        });
    }
};
