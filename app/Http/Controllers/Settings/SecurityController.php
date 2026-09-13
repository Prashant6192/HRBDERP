<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Identity\Services\PersonalPinService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Formulation\SetFormulaPinRequest;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class SecurityController extends Controller
{
    /**
     * Show the user's security settings page.
     */
    public function edit(TwoFactorAuthenticationRequest $request): Response
    {
        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'canManagePasskeys' => Features::canManagePasskeys(),
            'passkeys' => Features::canManagePasskeys()
                ? $request->user()
                    ->passkeys()
                    ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
                    ->latest()
                    ->get()
                    ->map(fn ($passkey) => [
                        'id' => $passkey->id,
                        'name' => $passkey->name,
                        'authenticator' => $passkey->authenticator,
                        'created_at_diff' => $passkey->created_at->diffForHumans(),
                        'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
                    ])
                    ->values()
                    ->all()
                : [],
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'hasPin' => $request->user()->hasFormulaPin(),
            'pinSetAt' => $request->user()->formula_pin_set_at?->toIso8601String(),
            'pinUses' => array_values(array_filter([
                $request->user()->can('qc.approve') || $request->user()->can('qc.reject') ? 'signing QC decisions' : null,
                $request->user()->can('formula.view') ? 'unlocking formulations' : null,
            ])),
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();

            $props['twoFactorEnabled'] = $request->user()->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        return Inertia::render('settings/security', $props);
    }

    /**
     * Set or change the personal PIN that signs QC decisions and unlocks
     * formulations.
     */
    public function updatePin(SetFormulaPinRequest $request, PersonalPinService $pins): RedirectResponse
    {
        $pins->set($request->user(), $request->validated('pin'));

        return back()->withToast('success', 'Your personal PIN is set.');
    }

    /**
     * Update the user's password.
     */
    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $request->user()->update([
            'password' => $request->password,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated.')]);

        return back();
    }
}
