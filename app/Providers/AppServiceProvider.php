<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        $this->configureDefaults();
        $this->configureModels();
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
