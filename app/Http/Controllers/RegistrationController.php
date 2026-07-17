<?php

namespace App\Http\Controllers;

use App\Mail\RegistrationVerificationMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class RegistrationController extends Controller
{
    protected const TOKEN_TTL_MINUTES = 60;

    /**
     * Step 1 — the user submits only their email. We create (or refresh) an
     * unverified account and email a link to finish setup.
     *
     * The response is intentionally identical whether or not the email is
     * already registered, to avoid leaking which addresses have accounts.
     */
    public function start(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $email = strtolower($validated['email']);
        $user = User::where('email', $email)->first();

        // Only (re)send a setup link when there is no verified account yet.
        if (! $user || $user->email_verified_at === null) {
            $token = Str::random(64);

            $user = $user ?? new User(['email' => $email]);
            $user->forceFill([
                'registration_token' => Hash::make($token),
                'registration_token_expires_at' => now()->addMinutes(self::TOKEN_TTL_MINUTES),
            ])->save();

            $setupUrl = rtrim(config('app.url'), '/').'/complete-registration?'.http_build_query([
                'email' => $email,
                'token' => $token,
            ]);

            Mail::to($email)->send(new RegistrationVerificationMail($setupUrl));
        }

        return response()->json([
            'message' => 'If that email can be registered, a verification link is on its way. Check your inbox.',
        ]);
    }

    /**
     * Step 2 — the user follows the emailed link and sets their name and
     * password. Verifies the token, activates the account, and logs in.
     */
    public function complete(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'name' => 'required|string|max:255',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::where('email', strtolower($validated['email']))->first();

        if (! $this->tokenIsValid($user, $validated['token'])) {
            return response()->json([
                'message' => 'This verification link is invalid or has expired. Please request a new one.',
            ], 422);
        }

        $user->forceFill([
            'name' => $validated['name'],
            'password' => Hash::make($validated['password']),
            'email_verified_at' => now(),
            'registration_token' => null,
            'registration_token_expires_at' => null,
        ])->save();

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json($user, 201);
    }

    protected function tokenIsValid(?User $user, string $token): bool
    {
        return $user
            && $user->email_verified_at === null
            && $user->registration_token
            && $user->registration_token_expires_at
            && $user->registration_token_expires_at->isFuture()
            && Hash::check($token, $user->registration_token);
    }
}
