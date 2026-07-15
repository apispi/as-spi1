<?php

namespace App\Http\Controllers;

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
