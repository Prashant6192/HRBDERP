<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController as FortifyPasswordResetLinkController;
use Laravel\Fortify\Http\Requests\SendPasswordResetLinkRequest;
use Throwable;

/**
 * "Forgot password", answered the same way whatever was typed.
 *
 * Fortify's own controller says "we can't find a user with that email
 * address" for an unknown one, which turns the form into a way to test
 * which employees have accounts. Here every well-formed request gets the
 * same reply, and only an active account's owner receives an email.
 *
 * Two limits keep the form from being used to flood an inbox:
 *
 *  - per address, quietly: after three requests in fifteen minutes the
 *    next ones send nothing but get the same reply, so the limit itself
 *    reveals nothing about whether the address exists;
 *  - per network, openly: after ten requests in fifteen minutes the form
 *    says so, since that limit applies to everyone alike.
 *
 * Bound over Fortify's controller in FortifyServiceProvider, so Fortify's
 * route and its name stay exactly as they are.
 */
class PasswordResetLinkController extends FortifyPasswordResetLinkController
{
    public const int PER_ADDRESS = 3;

    public const int PER_NETWORK = 10;

    public const int WINDOW_SECONDS = 15 * 60;

    public function store(SendPasswordResetLinkRequest $request): Responsable
    {
        $email = Str::lower(trim((string) $request->input(Fortify::email())));

        $network = 'password-reset:network:'.$request->ip();

        if (RateLimiter::tooManyAttempts($network, self::PER_NETWORK)) {
            $minutes = (int) ceil(RateLimiter::availableIn($network) / 60);

            throw ValidationException::withMessages([
                Fortify::email() => "Too many reset requests from this network. Try again in {$minutes} minute".($minutes === 1 ? '' : 's').'.',
            ]);
        }

        RateLimiter::hit($network, self::WINDOW_SECONDS);

        $address = 'password-reset:address:'.hash('sha256', $email);

        if (! RateLimiter::tooManyAttempts($address, self::PER_ADDRESS)) {
            RateLimiter::hit($address, self::WINDOW_SECONDS);

            try {
                // The outcome is deliberately not looked at: unknown, throttled
                // and deactivated all end in the same reply as a real send.
                $this->broker()->sendResetLink([Fortify::email() => $email]);
            } catch (Throwable $e) {
                // A mail provider refusing is ours to fix, not the visitor's
                // to learn about: telling them would say the address exists.
                report($e);
            }
        }

        return app(SuccessfulPasswordResetLinkRequestResponse::class, ['status' => Password::RESET_LINK_SENT]);
    }
}
