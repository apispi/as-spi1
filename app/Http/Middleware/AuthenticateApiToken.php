<?php

namespace App\Http\Middleware;

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

        $user = User::findByApiKey($token);

        if (! $user) {
            return response()->json(['message' => 'Invalid API key.'], 401);
        }

        Auth::setUser($user);

        return $next($request);
    }
}
