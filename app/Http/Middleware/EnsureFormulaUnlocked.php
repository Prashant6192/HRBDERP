<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Formulation\Services\FormulaSecurityService;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The gate in front of every route that would load a recipe.
 *
 * A user without a current unlock is sent to verify their PIN (or to set
 * one), with the page they wanted remembered. Responses that pass are
 * marked not to be cached anywhere: a recipe must not survive in a proxy or
 * the browser's back-forward cache.
 */
class EnsureFormulaUnlocked
{
    public function __construct(private readonly FormulaSecurityService $security) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $token = $request->hasSession() ? $this->security->sessionToken($request->session()) : null;

        if (! $this->security->isUnlocked($user, $token)) {
            if ($request->isMethod('GET')) {
                $request->session()->put('formula.intended', $request->fullUrl());
            }

            $target = $this->security->needsPinSetup($user) ? route('formulas.pin.edit') : route('formulas.unlock');

            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => 'Formulations are locked. Verify your PIN to continue.', 'unlock_url' => $target], 423);
            }

            return redirect()->to($target)->withToast('warning', 'Formulations are locked. Verify your PIN to continue.');
        }

        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
