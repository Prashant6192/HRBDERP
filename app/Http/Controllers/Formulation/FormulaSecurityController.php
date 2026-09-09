<?php

declare(strict_types=1);

namespace App\Http\Controllers\Formulation;

use App\Domain\Formulation\Exceptions\FormulaAccessException;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Formulation\Services\FormulaSecurityService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Formulation\SetFormulaPinRequest;
use App\Http\Requests\Formulation\UnlockFormulasRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The PIN screens: unlock, lock, and set or change the PIN.
 */
class FormulaSecurityController extends Controller
{
    public function __construct(private readonly FormulaSecurityService $security) {}

    public function showUnlock(Request $request): Response|RedirectResponse
    {
        $this->authorize('viewAny', Formula::class);

        $user = $request->user();

        if ($this->security->needsPinSetup($user)) {
            return redirect()->route('formulas.pin.edit')
                ->withToast('warning', 'Set a formula PIN first. You will use it every time you open a recipe.');
        }

        $lockedFor = $user->formula_pin_locked_until?->isFuture()
            ? max(1, (int) ceil(now()->diffInSeconds($user->formula_pin_locked_until, false) / 60))
            : null;

        return Inertia::render('formulas/unlock', [
            'requirePin' => $this->security->requiresPin(),
            'ttlMinutes' => $this->security->ttlMinutes(),
            'lockedForMinutes' => $lockedFor,
            'intended' => $request->session()->get('formula.intended'),
        ]);
    }

    public function unlock(UnlockFormulasRequest $request): RedirectResponse
    {
        $this->authorize('viewAny', Formula::class);

        try {
            $this->security->unlock(
                $request->user(),
                $request->validated('secret'),
                $this->security->sessionToken($request->session()),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (FormulaAccessException $e) {
            return back()->withErrors(['secret' => $e->getMessage()]);
        }

        $intended = $request->session()->pull('formula.intended');

        return redirect()->to($intended ?: route('formulas.index'))
            ->withToast('success', 'Formulations unlocked for '.$this->security->ttlMinutes().' minutes.');
    }

    public function lock(Request $request): RedirectResponse
    {
        $this->security->lock($request->user(), $request->ip(), $request->userAgent());

        return redirect()->route('formulas.index')->withToast('success', 'Formulations locked.');
    }

    public function editPin(Request $request): Response
    {
        $this->authorize('viewAny', Formula::class);

        $user = $request->user();

        return Inertia::render('formulas/pin', [
            'hasPin' => $user->hasFormulaPin(),
            'pinSetAt' => $user->formula_pin_set_at?->toIso8601String(),
            'ttlMinutes' => $this->security->ttlMinutes(),
        ]);
    }

    public function updatePin(SetFormulaPinRequest $request): RedirectResponse
    {
        $this->authorize('viewAny', Formula::class);

        $this->security->setPin($request->user(), $request->validated('pin'), $request->ip(), $request->userAgent());

        return redirect()->route('formulas.unlock')
            ->withToast('success', 'Formula PIN set. Enter it to unlock formulations.');
    }
}
