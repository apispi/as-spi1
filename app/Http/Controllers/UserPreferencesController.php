<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserPreferencesController extends Controller
{
    public function updateScxApiKey(Request $request)
    {
        $validated = $request->validate([
            'scx_api_key' => 'nullable|string|max:500',
        ]);

        $user = Auth::user();
        $user->scx_api_key = $validated['scx_api_key'] ?? null;
        $user->save();

        return response()->json(['message' => 'SCX API key saved successfully']);
    }

    public function getScxApiKey(Request $request)
    {
        $user = Auth::user();
        return response()->json([
            'scx_api_key' => $user->scx_api_key ? '********' : null,
            'has_key' => !empty($user->scx_api_key),
        ]);
    }
}