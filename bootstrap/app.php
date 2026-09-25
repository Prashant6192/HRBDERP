<?php

use App\Http\Middleware\EnsureFormulaUnlocked;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\KeepAgencyToLabels;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // The application is only reachable through the hosting platform's
        // load balancer, so trust the headers it adds. Without this, links
        // built from a request — the one in a password reset email, for
        // instance — come out as http://, and every IP address the audit
        // trail records is the balancer's own rather than the person's.
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,

            // Runs on every web request so that deactivating an account ends
            // any session it already has, rather than only blocking the next
            // sign-in.
            EnsureUserIsActive::class,

            // An outside e-commerce agency reaches the label upload screens,
            // its notifications and its own settings, and nothing else.
            KeepAgencyToLabels::class,

            // Remembers the password each session signed in with. When the
            // password changes — reset from an emailed link, or changed in
            // settings — every other session is signed out on its next
            // request, so a stolen session dies with the old password. The
            // session that made the change carries on. Works whatever the
            // session driver, Redis included.
            AuthenticateSession::class,
        ]);

        // Spatie's permission and role middleware, available to routes as
        // ->middleware('permission:inventory.receive').
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,

            // The second factor in front of recipes; see EnsureFormulaUnlocked.
            'formula.unlocked' => EnsureFormulaUnlocked::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
