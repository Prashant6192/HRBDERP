<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeds the ERP.
 *
 * Reference data — units, departments, roles and permissions — is seeded in
 * every environment, production included, and is safe to re-run.
 *
 * Demo data is not. It creates sixteen accounts with a published password, so
 * it is skipped in production unless someone explicitly asks for it by
 * running DemoDataSeeder directly.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            UomSeeder::class,
            DepartmentSeeder::class,
            RolePermissionSeeder::class,
            ReferenceDataSeeder::class,
        ]);

        if (app()->isProduction()) {
            $this->command?->warn('Production environment detected — demo data was not seeded.');

            return;
        }

        $this->call(DemoDataSeeder::class);
        $this->call(DemoOperationsSeeder::class);

        $this->command?->info('Demo accounts created. Password for all of them: "password".');
    }
}
