<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the framework's users table into an employee record.
 *
 * The formula PIN is the second factor guarding trade-secret formulations. It
 * is stored only as a hash, in the same manner as the password, and is never
 * returned by the API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('employee_code', 32)->nullable()->unique()->after('id');
            $table->foreignId('department_id')->nullable()->after('email')
                ->constrained('departments')->nullOnDelete();

            $table->string('phone', 32)->nullable()->after('department_id');
            $table->string('designation')->nullable()->after('phone');
            $table->string('avatar_path')->nullable()->after('designation');

            // 'active' | 'inactive' | 'suspended'. An account that is not
            // active cannot authenticate; see EnsureUserIsActive middleware.
            $table->string('status', 16)->default('active')->after('avatar_path');
            $table->timestamp('deactivated_at')->nullable()->after('status');

            $table->timestamp('last_login_at')->nullable()->after('deactivated_at');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');

            // Second-factor secret for formula access. Hashed, never plain.
            $table->string('formula_pin_hash')->nullable()->after('last_login_ip');
            $table->timestamp('formula_pin_set_at')->nullable()->after('formula_pin_hash');

            $table->boolean('must_change_password')->default(false)->after('formula_pin_set_at');

            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn([
                'employee_code', 'phone', 'designation', 'avatar_path',
                'status', 'deactivated_at', 'last_login_at', 'last_login_ip',
                'formula_pin_hash', 'formula_pin_set_at', 'must_change_password',
                'deleted_at',
            ]);
        });
    }
};
