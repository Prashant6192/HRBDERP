<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gap-free numbering for documents and batches.
 *
 * Batch numbers, goods receipts, manufacturing orders and material requests
 * all need a human-readable number that never repeats. Taking MAX()+1 races
 * under load; a database sequence cannot restart per day or per prefix. A row
 * per key, locked FOR UPDATE while the next value is taken, gives both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->string('key', 64)->primary();
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
