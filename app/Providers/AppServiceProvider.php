<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ->withToast('success', 'Saved.') on any redirect: the message rides
        // Inertia's flash bag and the Toaster shows it on the next page.
        RedirectResponse::macro('withToast', function (string $type, string $message): RedirectResponse {
            Inertia::flash('toast', ['type' => $type, 'message' => $message]);

            /** @var RedirectResponse $this */
            return $this;
        });

        $this->configureDefaults();
        $this->configureModels();
        $this->assertDatabaseIsPostgres();
    }

    /**
     * Refuse to run a production ERP on anything but PostgreSQL.
     *
     * The failure this prevents is a quiet one. A hosting platform that injects
     * database credentials without DB_CONNECTION leaves Laravel on its own
     * default, and the application then writes to a SQLite file instead of the
     * database that is backed up, replicated and paid for. Nothing looks wrong
     * until somebody asks where the stock went.
     *
     * Stopping at boot turns that into an error message naming the problem, at
     * the moment of deployment rather than weeks later.
     */
    protected function assertDatabaseIsPostgres(): void
    {
        if (! app()->isProduction()) {
            return;
        }

        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        if ($driver === 'pgsql') {
            return;
        }

        throw new RuntimeException(sprintf(
            'HRBD ERP requires PostgreSQL, but the active database connection [%s] uses the [%s] driver. '
            .'Set DB_CONNECTION=pgsql in this environment, along with DB_HOST, DB_PORT, DB_DATABASE, '
            .'DB_USERNAME and DB_PASSWORD. See DEPLOYMENT.md.',
            $connection,
            $driver ?? 'unknown',
        ));
    }

    /**
     * Model-wide conventions for the ERP.
     */
    protected function configureModels(): void
    {
        // ERP models live under App\Domain\<Module>\Models, which Laravel's
        // default factory resolver — which expects App\Models — cannot map.
        // Factories stay in one flat directory and are matched by class name.
        Factory::guessFactoryNamesUsing(
            static fn (string $model): string => 'Database\\Factories\\'.class_basename($model).'Factory',
        );

        // Assigning an attribute that is not fillable is a mistake in a domain
        // this size, not something to absorb silently: it means a form field
        // or an import column is quietly not being saved.
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
