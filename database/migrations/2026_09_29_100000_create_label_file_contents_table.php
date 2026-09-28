<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A copy of each label PDF in the database. The app's own disk is wiped
 * on every deploy on Laravel Cloud, and a label that cannot be printed
 * stops the day's dispatch; the database is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('label_file_contents', function (Blueprint $table): void {
            $table->foreignId('label_file_id')->primary()->constrained('label_files')->cascadeOnDelete();
            // Base64: a plain text column survives every driver and backup.
            $table->longText('data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_file_contents');
    }
};
