<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Formulations: the company's trade secret.
 *
 * A formula is the identity ("Oil Control Face Wash"); a version is one
 * concrete recipe for it; ingredients belong to a version. Only one version
 * of a formula may be active at a time — enforced by a partial unique index,
 * not by application code alone.
 *
 * Access to a recipe is a separate, short-lived unlock on top of the
 * permission system. Unlocks and every look at a recipe are recorded in
 * their own append-only tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            // The INCI name of a raw material, as printed on a product label.
            $table->string('inci_name')->nullable()->after('name');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedSmallInteger('formula_pin_failed_attempts')->default(0)->after('formula_pin_set_at');
            $table->timestamp('formula_pin_locked_until')->nullable()->after('formula_pin_failed_attempts');
        });

        Schema::create('formulas', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->foreignId('product_id')->nullable()->constrained('items')->nullOnDelete();
            $table->string('status', 16)->default('draft')->index();
            $table->unsignedBigInteger('active_version_id')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
        });

        Schema::create('formula_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('formula_id')->constrained('formulas')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status', 16)->default('draft')->index();

            // The reference batch the percentages describe, e.g. 100 g or
            // 100 ml. Scaling to a production batch starts from here.
            $table->decimal('batch_size', 20, 6)->default('100');
            $table->foreignId('batch_uom_id')->constrained('uoms')->restrictOnDelete();

            // Cached sum of the fixed percentages, so lists can show it
            // without loading every ingredient.
            $table->decimal('total_percentage', 12, 6)->default('0');

            $table->text('notes')->nullable();
            $table->string('change_summary')->nullable();
            $table->string('source', 16)->default('manual');
            $table->string('source_reference')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->unique(['formula_id', 'version_number']);
        });

        // One active recipe per formula, guaranteed by the database.
        DB::statement(
            "CREATE UNIQUE INDEX formula_versions_one_active ON formula_versions (formula_id) WHERE status = 'active'"
        );

        Schema::table('formulas', function (Blueprint $table): void {
            $table->foreign('active_version_id')->references('id')->on('formula_versions')->nullOnDelete();
        });

        Schema::create('formula_ingredients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('formula_version_id')->constrained('formula_versions')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();

            // The label name at the time the recipe was written; the item's
            // own INCI name may be corrected later without rewriting history.
            $table->string('inci_name')->nullable();

            // NULL percentage + is_qs: the filler that brings the batch to
            // 100 %. NULL percentage without is_qs: "as required", such as a
            // pH adjuster, which is dosed at the kettle and not planned.
            $table->decimal('percentage', 12, 6)->nullable();
            $table->boolean('is_qs')->default(false);
            $table->string('qs_note', 64)->nullable();

            $table->string('grade', 8)->nullable();
            $table->string('phase', 8)->nullable();
            $table->string('purpose', 128)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['formula_version_id', 'line_no']);
            $table->index('item_id');
        });

        DB::statement(
            'ALTER TABLE formula_ingredients ADD CONSTRAINT formula_ingredients_percentage_sane '
            .'CHECK (percentage IS NULL OR (percentage >= 0 AND percentage <= 100))'
        );

        Schema::create('formula_unlocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('session_id', 128)->nullable();
            $table->timestamp('unlocked_at');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->index(['user_id', 'expires_at']);
        });

        Schema::create('formula_access_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('formula_id')->nullable()->constrained('formulas')->nullOnDelete();
            $table->foreignId('formula_version_id')->nullable()->constrained('formula_versions')->nullOnDelete();
            $table->string('action', 32)->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->jsonb('context')->nullable();
            $table->timestamp('occurred_at')->index();

            $table->index(['user_id', 'occurred_at']);
            $table->index('formula_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formula_access_logs');
        Schema::dropIfExists('formula_unlocks');
        Schema::dropIfExists('formula_ingredients');

        Schema::table('formulas', function (Blueprint $table): void {
            $table->dropForeign(['active_version_id']);
        });

        Schema::dropIfExists('formula_versions');
        Schema::dropIfExists('formulas');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['formula_pin_failed_attempts', 'formula_pin_locked_until']);
        });

        Schema::table('items', function (Blueprint $table): void {
            $table->dropColumn('inci_name');
        });
    }
};
