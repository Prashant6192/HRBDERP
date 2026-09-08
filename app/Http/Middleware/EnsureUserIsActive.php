<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns account deactivation into something that takes effect immediately.
 *
 * Blocking deactivated accounts at the login screen alone would leave anyone
 * already signed in with a working session until it expired — which, for an
 * employee who has just been walked out of the building, is exactly the window
 * that matters. This runs on every authenticated request instead.
 */
class EnsureUserIsActive
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
        //
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->isActive()) {
            $this->auditLogger->log(
                action: AuditAction::LoggedOut,
                entity: $user,
                description: 'Session ended because the account is no longer active.',
                context: ['status' => $user->status->value],
                actor: $user,
            );

            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors([
                    'email' => 'Your account is no longer active. Please contact your administrator.',
                ]);
        }

        return $next($request);
    }
}
