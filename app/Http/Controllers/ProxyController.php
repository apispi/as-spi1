<?php

namespace App\Http\Controllers;

use App\Models\RequestHistory;
use App\Rules\PubliclyRoutableUrl;
use App\Services\Security\SsrfException;
use App\Http\Middleware\ResolveEnvironmentVariables;
use App\Services\Auth\RequestAuthenticator;
use App\Services\Security\SsrfGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Exception;

class ProxyController extends Controller
{
    public function handle(Request $request)
    {
        $validated = $request->validate([
            'url' => ['required', 'url', new PubliclyRoutableUrl],
            'method' => 'required|string|in:GET,POST,PUT,PATCH,DELETE,OPTIONS,HEAD',
            'headers' => 'nullable|array',
            // Callers send either a raw string body (the dashboard passes the
            // editor contents verbatim) or a decoded JSON object (the homepage
            // testers build one). Accept both and normalise below.
            'body' => 'nullable',
            // The auth helper's config: scheme plus its fields. Values arrive
            // already resolved, so a credential can live in a secret variable.
        ] + RequestAuthenticator::rules());

        $url = $validated['url'];
        $method = strtoupper($validated['method']);
        $headers = collect($validated['headers'] ?? [])->filter(function($value, $key) {
            // Filter out host and content-length headers to avoid conflict
            $k = strtolower($key);
            return !in_array($k, ['host', 'content-length']);
        })->toArray();
        $body = $validated['body'] ?? null;

        // Bearer/Basic/API-key are assembled here rather than in the browser,
        // so the credential never has to be built client-side.
        $authed = (new RequestAuthenticator)->apply(
            $validated['auth'] ?? null,
            $headers,
            $url,
            $request->attributes->get(ResolveEnvironmentVariables::ENVIRONMENT_AUTH)
        );
        if ($authed['error']) {
            return response()->json(['error' => $authed['error']], 422);
        }
        $headers = $authed['headers'];
        $url = $authed['url'];

        // Normalise a structured body to the JSON string we forward on the wire.
        if (is_array($body)) {
            $body = json_encode($body);
        } elseif ($body !== null && ! is_string($body)) {
            $body = (string) $body;
        }

        // Pin the host to the IP we validate now, so it cannot re-resolve to an
        // internal address between validation and connection (DNS rebinding).
        try {
            $pinned = (new SsrfGuard)->pinnedOptions($url);
        } catch (SsrfException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $startTime = microtime(true);

        try {
            // Do not follow redirects: a redirect to an internal address
            // would bypass the SSRF validation done on the original URL.
            $pendingRequest = Http::withHeaders($headers)
                ->withoutVerifying()
                ->withOptions(['allow_redirects' => false] + $pinned);
            
            $response = null;
            if (in_array($method, ['POST', 'PUT', 'PATCH']) && !empty($body)) {
                // To send raw body, we can use send() with body option
                $response = $pendingRequest->send($method, $url, [
                    'body' => $body
                ]);
            } else {
                $response = $pendingRequest->send($method, $url);
            }

            $endTime = microtime(true);
            $timeTakenMs = round(($endTime - $startTime) * 1000);

            if ($request->user()) {
                RequestHistory::record($request->user()->id, [
                    'protocol' => 'rest',
                    'method' => $method,
                    'url' => $url,
                    'body' => $body,
                    'status' => $response->status(),
                    'time_ms' => $timeTakenMs,
                ]);
            }

            return response()->json([
                'status' => $response->status(),
                'headers' => $response->headers(),
                'body' => $response->body(),
                'time_ms' => $timeTakenMs,
                'request_payload' => $body,
                'request_headers' => $headers,
            ]);

        } catch (Exception $e) {
            $endTime = microtime(true);
            $timeTakenMs = round(($endTime - $startTime) * 1000);

            if ($request->user()) {
                RequestHistory::record($request->user()->id, [
                    'protocol' => 'rest',
                    'method' => $method,
                    'url' => $url,
                    'body' => $body,
                    'status' => null,
                    'time_ms' => $timeTakenMs,
                ]);
            }

            return response()->json([
                'status' => 500,
                'headers' => [],
                'body' => 'Proxy Error: ' . $e->getMessage(),
                'time_ms' => $timeTakenMs,
            ], 500);
        }
    }
}
