<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a request using a personal API key sent as a bearer token.
 *
 * Used only by the stateless /api/v1 routes. Those routes carry no session
 * or CSRF middleware, so there is no ambient cookie credential to protect —
 * which is exactly why programmatic access lives on its own route group
 * rather than sharing the session-authenticated SPA routes.
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['message' => 'API key required.'], 401);
        }

        // Resolve the named key first so its scopes can be enforced downstream;
        // fall back to the legacy single api_token column (which is unscoped).
        if ($key = ApiKey::resolve($token)) {
            $key->forceFill(['last_used_at' => now()])->save();
            Auth::setUser($key->user);
            $request->attributes->set('api_key', $key);

            return $next($request);
        }

        $user = User::where('api_token', User::hashApiKey($token))->first();

        if (! $user) {
            return response()->json(['message' => 'Invalid API key.'], 401);
        }

        Auth::setUser($user);

        return $next($request);
    }
}
