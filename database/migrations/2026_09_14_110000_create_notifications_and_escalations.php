<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app notifications (Laravel's database channel) and the record of what
 * the escalation engine has already raised, so a QC batch pending for a
 * day is escalated once per level, not once per hour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->jsonb('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });

        Schema::create('escalations', function (Blueprint $table): void {
            $table->id();
            // The exception's key: "<rule>:<subject>", e.g. "slow_qc:QCI-0012".
            $table->string('exception_key', 191);
            $table->string('rule', 64);
            $table->unsignedSmallInteger('level');
            $table->string('title');
            $table->string('href')->nullable();
            $table->jsonb('roles');
            $table->unsignedInteger('recipients')->default(0);
            $table->timestamp('escalated_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['exception_key', 'level']);
            $table->index(['rule', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escalations');
        Schema::dropIfExists('notifications');
    }
};
