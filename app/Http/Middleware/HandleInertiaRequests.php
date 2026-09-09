<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Formulation\Services\FormulaSecurityService;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                'roles' => $user instanceof User ? $user->getRoleNames()->values()->all() : [],

                // Sent so the interface can hide what the user cannot do.
                // This is a convenience, not a control: every one of these
                // permissions is checked again by a policy before the server
                // does anything. See SECURITY_ARCHITECTURE.md.
                'permissions' => $this->permissionsFor($user),
                'isSuperAdmin' => $user instanceof User && $user->isSuperAdmin(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',

            // Whether the second factor in front of recipes is currently
            // cleared. Only computed for users who could see formulas at all.
            'formulaAccess' => fn () => $this->formulaAccessFor($request, $user),
        ];
    }

    /**
     * @return array{unlocked: bool, expires_at: string|null, minutes_remaining: int, needs_pin: bool, ttl_minutes: int, require_pin: bool}|null
     */
    private function formulaAccessFor(Request $request, ?object $user): ?array
    {
        if (! $user instanceof User || ! $user->can('formula.view')) {
            return null;
        }

        $security = app(FormulaSecurityService::class);
        $unlock = $security->currentUnlock($user, $request->hasSession() ? $security->sessionToken($request->session()) : null);

        return [
            'unlocked' => $unlock !== null,
            'expires_at' => $unlock?->expires_at->toIso8601String(),
            'minutes_remaining' => $unlock?->minutesRemaining() ?? 0,
            'needs_pin' => $security->needsPinSetup($user),
            'ttl_minutes' => $security->ttlMinutes(),
            'require_pin' => $security->requiresPin(),
        ];
    }

    /**
     * The permission names the current user holds.
     *
     * A Super Admin holds every permission through a gate rather than through
     * permission rows, so the list is built from the catalogue for them.
     *
     * @return list<string>
     */
    private function permissionsFor(?object $user): array
    {
        if (! $user instanceof User) {
            return [];
        }

        if ($user->isSuperAdmin()) {
            return PermissionCatalogue::all();
        }

        return $user->getAllPermissions()->pluck('name')->values()->all();
    }
}
