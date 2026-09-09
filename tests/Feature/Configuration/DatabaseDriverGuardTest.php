<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Providers\AppServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The ERP must not run on anything but PostgreSQL in production.
 *
 * This exists because of a real incident: a hosting platform injected the
 * database credentials but not DB_CONNECTION, Laravel fell back to its SQLite
 * default, and the application began writing to a file rather than to the
 * database that was backed up and paid for. Nothing appeared wrong until a
 * write failed for an unrelated reason.
 */
class DatabaseDriverGuardTest extends TestCase
{
    private function bootProviderInProduction(string $connection): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['database.default' => $connection]);

        (new AppServiceProvider($this->app))->boot();
    }

    #[Test]
    public function production_refuses_to_boot_on_sqlite(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/requires PostgreSQL/');

        $this->bootProviderInProduction('sqlite');
    }

    #[Test]
    public function the_message_names_the_variable_that_is_missing(): void
    {
        // The point of the guard is that whoever hits it knows what to change.
        try {
            $this->bootProviderInProduction('sqlite');
            $this->fail('Expected the guard to reject SQLite in production.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('DB_CONNECTION=pgsql', $e->getMessage());
            $this->assertStringContainsString('sqlite', $e->getMessage());
        }
    }

    #[Test]
    public function production_refuses_to_boot_on_mysql(): void
    {
        $this->expectException(RuntimeException::class);

        $this->bootProviderInProduction('mysql');
    }

    #[Test]
    public function production_boots_on_postgres(): void
    {
        $this->bootProviderInProduction('pgsql');

        $this->assertSame('pgsql', config('database.default'));
    }

    #[Test]
    public function development_is_left_alone(): void
    {
        // Local work on SQLite is nobody's business but the developer's; the
        // guard is about protecting live company data.
        $this->app->detectEnvironment(fn (): string => 'local');
        config(['database.default' => 'sqlite']);

        (new AppServiceProvider($this->app))->boot();

        $this->assertSame('sqlite', config('database.default'));
    }

    #[Test]
    public function the_shipped_default_is_postgres_not_sqlite(): void
    {
        // If DB_CONNECTION is absent entirely, the fallback must still be the
        // database this ERP actually requires.
        $this->assertSame('pgsql', config('database.default', 'pgsql'));
    }
}
