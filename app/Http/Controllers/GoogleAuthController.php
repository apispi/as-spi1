<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleAuthController extends Controller
{
    /**
     * Send the user to Google's consent screen. Used for both login and
     * register — the callback creates the account if it does not exist.
     */
    public function redirect()
    {
        if (! config('services.google.client_id')) {
            return redirect('/login?error=google_unavailable');
        }

        return Socialite::driver('google')->redirect();
    }

    /**
     * Handle the callback: link to an existing account by google_id or email,
     * otherwise create one, then log in and return to the dashboard.
     */
    public function callback()
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            // Log the real cause (invalid state, bad secret, redirect mismatch)
            // so failures are diagnosable instead of an opaque error code.
            Log::warning('Google OAuth callback failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return redirect('/login?error=google_failed');
        }

        $email = $googleUser->getEmail();

        if (! $email) {
            return redirect('/login?error=google_no_email');
        }

        // Existing Google-linked account, or an existing email account we link
        // to Google on first use, or a brand-new account.
        $user = User::where('google_id', $googleUser->getId())->first()
            ?? User::where('email', $email)->first();

        if ($user) {
            $user->forceFill([
                'google_id' => $googleUser->getId(),
                'avatar' => $user->avatar ?: $googleUser->getAvatar(),
            ])->save();
        } else {
            $user = User::create([
                'name' => $googleUser->getName() ?: Str::before($email, '@'),
                'email' => $email,
                'google_id' => $googleUser->getId(),
                'avatar' => $googleUser->getAvatar(),
                // No usable password; account is OAuth-only until one is set.
                'password' => null,
            ]);
        }

        Auth::login($user, remember: true);
        request()->session()->regenerate();

        return redirect('/dashboard');
    }
}
