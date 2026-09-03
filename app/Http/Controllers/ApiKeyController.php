<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use Illuminate\Http\Request;

/**
 * Manage a user's named API keys. Keys are personal credentials: only the
 * plaintext returned once at creation is ever the full key.
 */
class ApiKeyController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            $request->user()->apiKeys()->latest('id')->get()->map->toClientArray()->values()
        );
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $activeCount = $user->apiKeys()->whereNull('revoked_at')->count();
        if ($activeCount >= ApiKey::MAX_PER_USER) {
            return response()->json(['message' => 'API key limit reached ('.ApiKey::MAX_PER_USER.'). Revoke one first.'], 422);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:60',
            'expires_at' => 'nullable|date|after:now',
        ]);

        [$key, $plain] = ApiKey::issue(
            $user,
            $validated['name'],
            isset($validated['expires_at']) ? new \DateTimeImmutable($validated['expires_at']) : null
        );

        // The plaintext is shown exactly once.
        return response()->json($key->toClientArray() + ['plaintext' => $plain], 201);
    }

    public function destroy(Request $request, int $id)
    {
        $key = $request->user()->apiKeys()->findOrFail($id);

        // Revoke rather than delete, so its last-used history and audit remain.
        $key->forceFill(['revoked_at' => now()])->save();

        return response()->json(['message' => 'Key revoked.']);
    }
}
