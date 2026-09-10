<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\AuditEvent;
use App\Models\User;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email:filter|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        AuditEvent::record('auth.register', $user, $request);
        $this->sendWelcome($user);

        return response()->json($user, 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|string|email:filter',
            'password' => 'required|string',
        ]);

        // Validate credentials WITHOUT establishing the session, so a
        // two-factor user is not logged in before their second factor.
        if (Auth::validate($credentials)) {
            $user = User::where('email', $credentials['email'])->first();

            if ($user && $user->hasTwoFactorEnabled()) {
                // Stash a short-lived challenge; the code is verified next.
                $request->session()->put('2fa:pending_user', $user->id);

                return response()->json(['two_factor_required' => true]);
            }

            Auth::login($user);
            $request->session()->regenerate();
            AuditEvent::record('auth.login', $user, $request);

            return response()->json($user);
        }

        // Record the failed attempt against the email tried (no user id).
        AuditEvent::record('auth.login_failed', $credentials['email'], $request);

        return response()->json([
            'message' => 'The provided credentials do not match our records.'
        ], 401);
    }

    /**
     * Second step of a two-factor login: verify the TOTP code (or a recovery
     * code) for the user whose password already checked out this session.
     */
    public function loginTwoFactor(Request $request, \App\Services\Auth\Totp $totp)
    {
        $validated = $request->validate(['code' => 'required|string']);

        $userId = $request->session()->get('2fa:pending_user');
        $user = $userId ? User::find($userId) : null;

        if (! $user || ! $user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'No two-factor challenge in progress. Sign in again.'], 401);
        }

        $code = trim($validated['code']);
        $ok = $totp->verify($user->two_factor_secret, $code) || $user->useRecoveryCode($code);

        if (! $ok) {
            AuditEvent::record('auth.2fa_failed', $user, $request);

            return response()->json(['message' => 'That code is not valid.'], 422);
        }

        $request->session()->forget('2fa:pending_user');
        Auth::login($user);
        $request->session()->regenerate();
        AuditEvent::record('auth.login', $user, $request, ['two_factor' => true]);

        return response()->json($user);
    }

    public function logout(Request $request)
    {
        AuditEvent::record('auth.logout', $request->user(), $request);

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out']);
    }

    public function user(Request $request)
    {
        return response()->json($request->user());
    }

    public function changePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Your current password is incorrect.',
            ], 422);
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        // Keep the current session valid but rotate its id.
        $request->session()->regenerate();

        AuditEvent::record('auth.password_changed', $user, $request);

        // Security notice — a changed password the owner didn't make is the
        // signal that matters. Never let mail trouble fail the change itself.
        try {
            \Illuminate\Support\Facades\Mail::to($user->email)
                ->send(new \App\Mail\PasswordChangedMail($user, $request->ip()));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Password-changed email failed', ['user' => $user->id, 'error' => $e->getMessage()]);
        }

        return response()->json(['message' => 'Password updated successfully.']);
    }

    /**
     * Send the welcome email, but never let a mail misconfiguration break the
     * sign-up the user just completed.
     */
    private function sendWelcome(User $user): void
    {
        try {
            \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\WelcomeMail($user));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Welcome email failed', ['user' => $user->id, 'error' => $e->getMessage()]);
        }
    }
}
