<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a finished batch was boxed, so its carton labels print the same way
 * every time and a box number always means the same box.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_lots', function (Blueprint $table): void {
            $table->jsonb('carton_plan')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_lots', function (Blueprint $table): void {
            $table->dropColumn('carton_plan');
        });
    }
};
