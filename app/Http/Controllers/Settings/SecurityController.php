<?php

namespace App\Http\Controllers\Settings;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use App\Services\AuditLogger;
use App\Support\SaveToast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
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
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();

            $props['twoFactorEnabled'] = $request->user()->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        return Inertia::render('settings/Security', $props);
    }

    /**
     * Update the user's password and sign the account out of its other
     * sessions; this one stays open. A new password equal to the current one
     * writes nothing.
     */
    public function update(PasswordUpdateRequest $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        $password = $request->string('password')->value();
        $changed = ! Hash::check($password, $user->password);

        if ($changed) {
            $user->update(['password' => $password]);
            $audit->record(AuditAction::AuthPasswordChanged, $user, [
                'sessions_closed' => $user->closeSessions($request->session()->getId()),
            ], $user);
        }

        SaveToast::flash($changed, 'Contraseña actualizada.');

        return back();
    }
}
