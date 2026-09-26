<?php

declare(strict_types=1);

use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Give each online brand its dispatch store when it has none: the finished
 * goods store of the Delhi depot, found by "Paper Market" in its name or
 * address, else as the Delhi facility that is not a factory. A store
 * already chosen on the Brands screen is never changed. Runs with the deploy,
 * which does not run `db:seed`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('brands')) {
            return;
        }

        ReferenceDataSeeder::ensureBrands();
    }

    public function down(): void
    {
        // Nothing to undo: the Brands screen changes the store.
    }
};
