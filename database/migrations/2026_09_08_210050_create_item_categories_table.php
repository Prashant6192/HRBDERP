<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');

            // Which kind of item this category organises. Null means it may be
            // used by any type.
            $table->string('item_type', 32)->nullable();

            $table->foreignId('parent_id')->nullable()
                ->constrained('item_categories')->nullOnDelete();

            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['item_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_categories');
    }
};
