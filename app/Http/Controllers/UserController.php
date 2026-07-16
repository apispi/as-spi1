<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    public function updateProfile(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $user = $request->user();
        $user->name = $validated['name'];
        $user->save();

        return response()->json(['name' => $user->name]);
    }

    public function stats(Request $request)
    {
        $user = $request->user();

        $activeDays = $user->requestHistories()
            ->where('created_at', '>=', now()->subDays(30))
            ->get(['created_at'])
            ->map(fn ($h) => $h->created_at->toDateString())
            ->unique()
            ->count();

        return response()->json([
            'requests' => $user->requestHistories()->count(),
            'saved' => $user->savedRequests()->count(),
            // Response sizes are not persisted, so bandwidth is not tracked.
            'bandwidth' => 0,
            'active_days' => $activeDays,
        ]);
    }

    public function activity(Request $request)
    {
        $entries = $request->user()->requestHistories()
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return response()->json($entries->map(function ($entry) {
            $label = $entry->protocol === 'rest' ? $entry->method : strtoupper($entry->protocol).' '.$entry->method;

            return [
                'action' => 'request.'.$entry->protocol,
                'description' => trim($label).' '.$entry->url,
                'created_at' => $entry->created_at->toIso8601String(),
            ];
        }));
    }

    /**
     * Describe the user's personal API key. The plaintext is never stored, so
     * only a masked hint is available after creation.
     */
    public function apiKey(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'has_key' => $user->api_token !== null,
            'masked' => $user->api_token
                ? User::API_KEY_PREFIX.str_repeat('•', 8).$user->api_token_last_four
                : null,
            'created_at' => $user->api_token_created_at?->toIso8601String(),
        ]);
    }

    /**
     * Issue a new key, invalidating any previous one. The plaintext is
     * returned here and never again.
     */
    public function regenerateApiKey(Request $request)
    {
        $plain = $request->user()->generateApiKey();

        return response()->json([
            'api_key' => $plain,
            'message' => 'Copy this key now — it will not be shown again.',
        ], 201);
    }

    /**
     * Default personalisation preferences, merged over whatever the user has
     * saved so the client always receives a complete set.
     */
    public const DEFAULT_PREFERENCES = [
        'default_protocol' => 'rest',
        'default_method' => 'GET',
        'timezone' => 'UTC',
        'compact_history' => false,
    ];

    public function preferences(Request $request)
    {
        return response()->json($this->resolvePreferences($request->user()->preferences));
    }

    public function updatePreferences(Request $request)
    {
        $validated = $request->validate([
            'default_protocol' => 'required|in:rest,mcp,a2a',
            'default_method' => 'required|in:GET,POST,PUT,PATCH,DELETE',
            'timezone' => 'required|timezone',
            'compact_history' => 'required|boolean',
        ]);

        $user = $request->user();
        $user->preferences = $validated;
        $user->save();

        return response()->json($this->resolvePreferences($validated));
    }

    protected function resolvePreferences(?array $saved): array
    {
        return array_merge(self::DEFAULT_PREFERENCES, $saved ?? []);
    }

    public function deleteAccount(Request $request)
    {
        $user = $request->user();

        // An admin deleting their own account could orphan the panel; block it.
        if ($user->isAdmin()) {
            return response()->json([
                'message' => 'Admin accounts cannot be self-deleted. Contact another admin.',
            ], 422);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $user->delete();

        return response()->json(['message' => 'Account deleted.']);
    }
}
