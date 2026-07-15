<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SavedRequest;

class SavedRequestController extends Controller
{
    /**
     * Saved-request cap for the free plan, matching the pricing page.
     * Admins are exempt; paid plans will lift this when billing exists.
     */
    public const FREE_PLAN_LIMIT = 10;

    public function index(Request $request)
    {
        return response()->json($request->user()->savedRequests()->latest()->get());
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if (! $user->isAdmin() && $user->savedRequests()->count() >= self::FREE_PLAN_LIMIT) {
            return response()->json([
                'message' => 'Free plan limit reached ('.self::FREE_PLAN_LIMIT.' saved requests). Delete one to save another.',
            ], 422);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'protocol' => 'nullable|string|in:rest,mcp,a2a',
            'method' => 'required|string',
            'url' => 'required|url',
            'headers' => 'nullable|array',
            'body' => 'nullable|string',
            'params' => 'nullable|array',
        ]);

        $validated['protocol'] = $validated['protocol'] ?? 'rest';

        $savedRequest = $user->savedRequests()->create($validated);

        return response()->json($savedRequest, 201);
    }

    public function destroy(Request $request, $id)
    {
        $savedRequest = $request->user()->savedRequests()->findOrFail($id);
        $savedRequest->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
