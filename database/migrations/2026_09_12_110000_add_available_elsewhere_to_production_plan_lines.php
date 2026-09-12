<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A requirement line remembers what other facilities could send when the
 * planning facility is short, so the plan screen can offer a transfer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_plan_lines', function (Blueprint $table): void {
            $table->jsonb('available_elsewhere')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('production_plan_lines', function (Blueprint $table): void {
            $table->dropColumn('available_elsewhere');
        });
    }
};
