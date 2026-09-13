<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Controls: who checks whose work, signed approval actions, reversals in
 * the ledger instead of edits, and controlled documents with versions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Maker-checker: the person who authorises what this employee raises.
            $table->foreignId('approving_authority_id')->nullable()->after('department_id')->constrained('users')->nullOnDelete();
        });

        Schema::table('approval_actions', function (Blueprint $table): void {
            // A digital signature over the action: who, what, when, on which
            // record — so an approval can be verified later and never edited.
            $table->string('signature_hash', 64)->nullable()->after('ip_address');
            $table->string('user_agent', 255)->nullable()->after('signature_hash');
        });

        Schema::table('inventory_transactions', function (Blueprint $table): void {
            // A posting that undoes another, line for line. The original is
            // never touched; both stay in the ledger.
            $table->foreignId('reverses_transaction_id')->nullable()->after('reference_id')->constrained('inventory_transactions')->restrictOnDelete();
            $table->index('reverses_transaction_id');
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            // One code for every version of the same document, e.g. SOP-QC-004.
            $table->string('code', 64);
            $table->unsignedSmallInteger('version');
            $table->string('kind', 32);
            $table->string('title');
            $table->string('status', 16)->default('draft');
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_mime', 128)->nullable();
            $table->text('change_summary')->nullable();
            $table->text('notes')->nullable();
            $table->date('effective_from')->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            $table->unique(['code', 'version']);
            $table->index(['kind', 'status']);
            $table->index(['item_id', 'status']);
        });

        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_status CHECK (status IN ('draft', 'approved', 'superseded', 'withdrawn'))");
        // Only one approved version per code at a time.
        DB::statement("CREATE UNIQUE INDEX documents_one_approved_per_code ON documents (code) WHERE status = 'approved'");
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
        Schema::table('inventory_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reverses_transaction_id');
        });
        Schema::table('approval_actions', fn (Blueprint $table) => $table->dropColumn(['signature_hash', 'user_agent']));
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('approving_authority_id'));
    }
};
