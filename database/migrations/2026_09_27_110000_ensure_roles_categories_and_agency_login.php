<?php

declare(strict_types=1);

use Database\Seeders\OutsideAccountsSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * The live deploy runs migrations but not `db:seed`, so what the seeders
 * set up never reached it: the permissions and roles for online orders,
 * the product categories, and the agency's login. This runs the same
 * seeders once, as part of the deploy.
 *
 * All three only add what is missing. Roles an administrator has changed
 * are not reset (they only gain abilities new to the catalogue); the
 * agency's login is created only if it does not exist. Tests seed their
 * own data, so nothing runs there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        (new RolePermissionSeeder)->run();
        (new ReferenceDataSeeder)->run();
        (new OutsideAccountsSeeder)->run();
    }

    public function down(): void
    {
        // Nothing to undo: the rows it adds are ordinary reference data and
        // the agency's login is managed on the Users screen.
    }
};
