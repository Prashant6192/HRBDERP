<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Marketplace\Services\BrandAccess;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An outside e-commerce agency signs in to upload the day's labels, and
 * for nothing else. Rather than trusting every other screen to refuse it,
 * the agency may only reach the pages listed here: the online-orders
 * screens it uploads through, its notifications, and its own account
 * settings. Any other page takes it back to online orders; any other
 * action is refused.
 *
 * The screens it may reach still check its brands and permissions
 * themselves.
 */
class KeepAgencyToLabels
{
    /**
     * Route names an agency may use. A trailing '*' matches a prefix.
     */
    private const ALLOWED = [
        'online-orders.index',
        'online-orders.create',
        'online-orders.store',
        'online-orders.show',
        'online-orders.close',
        'online-orders.files.show',
        'notifications.*',
        'profile.*',
        'user-password.*',
        'appearance.*',
        'security.*',
        'two-factor.*',
        'passkey.*',
        'password.*',
        'verification.*',
        'login*',
        'logout',
    ];

    public function __construct(private readonly BrandAccess $brands) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->brands->isRestricted($user)) {
            return $next($request);
        }

        $name = $request->route()?->getName();

        if ($name !== null && $this->allowed($name)) {
            return $next($request);
        }

        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return redirect()->route('online-orders.index');
        }

        abort(403, 'This account can only upload marketplace labels.');
    }

    private function allowed(string $name): bool
    {
        foreach (self::ALLOWED as $pattern) {
            if (str_ends_with($pattern, '*')
                ? str_starts_with($name, substr($pattern, 0, -1))
                : $name === $pattern) {
                return true;
            }
        }

        return false;
    }
}
