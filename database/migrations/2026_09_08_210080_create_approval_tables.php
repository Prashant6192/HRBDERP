<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One approval engine for the whole ERP.
 *
 * Formula releases, purchase orders, production orders, stock adjustments,
 * write-offs and price overrides all need the same thing: a request, an
 * ordered set of steps, a record of who did what, and a final state. They get
 * it from these three tables rather than from six near-identical
 * implementations.
 *
 * A workflow is identified by 'workflow_key' and its step template lives in
 * config/approvals.php, so adding a workflow is configuration rather than
 * schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approvals', function (Blueprint $table): void {
            $table->id();

            // What is being approved.
            $table->string('approvable_type');
            $table->unsignedBigInteger('approvable_id');

            // e.g. 'formula.release', 'purchase.order', 'stock.adjustment'
            $table->string('workflow_key', 64);

            // 'pending' | 'approved' | 'rejected' | 'cancelled' | 'returned'
            $table->string('status', 16)->default('pending');

            $table->unsignedSmallInteger('current_step')->default(1);

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();

            $table->text('request_note')->nullable();
            $table->jsonb('context')->nullable();

            $table->timestamps();

            $table->index(['approvable_type', 'approvable_id']);
            $table->index(['workflow_key', 'status']);
            $table->index('status');
        });

        Schema::create('approval_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('approval_id')->constrained('approvals')->cascadeOnDelete();

            $table->unsignedSmallInteger('sequence');
            $table->string('name');

            // Who may act on this step. A step names a permission, a role, or
            // both; holding either is enough unless 'require_both' is set.
            $table->string('required_permission')->nullable();
            $table->string('required_role')->nullable();
            $table->boolean('require_both')->default(false);

            // 'pending' | 'approved' | 'rejected' | 'skipped'
            $table->string('status', 16)->default('pending');

            $table->foreignId('acted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acted_at')->nullable();

            $table->timestamps();

            $table->unique(['approval_id', 'sequence']);
        });

        Schema::create('approval_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('approval_id')->constrained('approvals')->cascadeOnDelete();
            $table->foreignId('approval_step_id')->nullable()
                ->constrained('approval_steps')->nullOnDelete();

            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            // 'approved' | 'rejected' | 'returned' | 'cancelled' | 'commented'
            $table->string('action', 16);
            $table->text('comment')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->timestamp('acted_at')->useCurrent();

            $table->index(['approval_id', 'acted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_actions');
        Schema::dropIfExists('approval_steps');
        Schema::dropIfExists('approvals');
    }
};
