<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable record of every consequential action in the ERP.
 *
 * Rows are only ever inserted. The application enforces this through the
 * AuditLog model (updates and deletes throw), and production deployments
 * should additionally revoke UPDATE and DELETE on this table from the
 * application role — see SECURITY_ARCHITECTURE.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();

            // Who. Nullable because system/queue actions have no user, and
            // because a user record must never be erased to hide an action.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('user_email')->nullable();

            // What. e.g. 'updated', 'approved', 'stock.adjusted', 'formula.viewed'
            $table->string('action', 64);
            $table->string('description', 512)->nullable();

            // Which entity. Stored as a polymorphic pair, but deliberately not
            // a morphTo relation constraint: audit rows must outlive the row
            // they describe.
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->string('auditable_label')->nullable();

            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();

            // Where from.
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('session_id')->nullable();
            $table->string('route')->nullable();

            // Free-form context (batch number, order reference, reason).
            $table->jsonb('context')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['user_id', 'created_at']);
            $table->index('action');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
