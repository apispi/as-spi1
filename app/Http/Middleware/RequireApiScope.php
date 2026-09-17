<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces an API-key scope on a /api/v1 route. Runs after AuthenticateApiToken,
 * which stashes the resolved ApiKey on the request. A legacy api_token (no key
 * row) is unscoped and always allowed, preserving pre-scopes behaviour.
 */
class RequireApiScope
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $key = $request->attributes->get('api_key');

        // No key row → legacy token → full access.
        if ($key && ! $key->hasScope($ability)) {
            return response()->json([
                'message' => "This API key is not authorised for '{$ability}'.",
            ], 403);
        }

        return $next($request);
    }
}
