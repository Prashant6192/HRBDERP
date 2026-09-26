<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A login for someone outside the company — the e-commerce agency — is not
 * an employee: no employee code, no department, not on the Employees list.
 * Such an account may sign in with a username instead of an email address.
 *
 * Additive only: two nullable/defaulted columns, nothing existing changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username', 64)->nullable()->unique()->after('email');
            $table->boolean('is_external')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'is_external']);
        });
    }
};
