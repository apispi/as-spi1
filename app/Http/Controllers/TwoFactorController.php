<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Services\Auth\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Manage a user's own two-factor authentication (TOTP).
 *
 * Setup is two-step: generate a secret (unconfirmed), then confirm it with a
 * code from the authenticator app before it takes effect. That proves the app
 * is configured correctly, so a user can never lock themselves out with a
 * mistyped secret. Recovery codes are issued once on confirmation.
 */
class TwoFactorController extends Controller
{
    public function __construct(private readonly Totp $totp)
    {
    }

    public function status(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'enabled' => $user->hasTwoFactorEnabled(),
            // A secret exists but is not yet confirmed → mid-setup.
            'pending' => ! $user->hasTwoFactorEnabled() && ! empty($user->two_factor_secret),
        ]);
    }

    /**
     * Begin setup: generate a fresh secret (replacing any unconfirmed one) and
     * return the provisioning URI + secret for the authenticator app.
     */
    public function setup(Request $request)
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Two-factor is already enabled. Disable it first to re-enrol.'], 422);
        }

        $secret = $this->totp->generateSecret();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json([
            'secret' => $secret,
            'otpauth_uri' => $this->totp->otpauthUri($secret, $user->email),
        ]);
    }

    /**
     * Confirm setup with a code, activating 2FA and issuing recovery codes
     * (returned once, here only).
     */
    public function confirm(Request $request)
    {
        $validated = $request->validate(['code' => 'required|string']);
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Two-factor is already enabled.'], 422);
        }
        if (empty($user->two_factor_secret)) {
            return response()->json(['message' => 'Start setup first.'], 422);
        }
        if (! $this->totp->verify($user->two_factor_secret, $validated['code'])) {
            return response()->json(['message' => 'That code is not valid. Check your authenticator app and try again.'], 422);
        }

        $codes = $this->makeRecoveryCodes();
        $user->forceFill([
            'two_factor_recovery_codes' => $codes,
            'two_factor_confirmed_at' => now(),
        ])->save();

        AuditEvent::record('two_factor.enabled', $user, $request);

        return response()->json([
            'message' => 'Two-factor authentication is on.',
            'recovery_codes' => array_map(fn ($c) => $c['code'], $codes),
        ]);
    }

    /**
     * Disable 2FA. Requires the account password (a code alone should not turn
     * off a second factor an attacker with the phone might have).
     */
    public function disable(Request $request)
    {
        $validated = $request->validate(['password' => 'required|string']);
        $user = $request->user();

        if (! Hash::check($validated['password'], $user->password)) {
            return response()->json(['message' => 'Your password is incorrect.'], 422);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        AuditEvent::record('two_factor.disabled', $user, $request);

        return response()->json(['message' => 'Two-factor authentication is off.']);
    }

    /** Remaining (unused) recovery-code count, for the settings display. */
    public function recoveryStatus(Request $request)
    {
        $codes = $request->user()->two_factor_recovery_codes ?? [];

        return response()->json([
            'remaining' => collect($codes)->whereNull('used_at')->count(),
            'total' => count($codes),
        ]);
    }

    /** @return array<int,array{code:string,used_at:null}> */
    private function makeRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = ['code' => Str::lower(Str::random(5)).'-'.Str::lower(Str::random(5)), 'used_at' => null];
        }

        return $codes;
    }
}
