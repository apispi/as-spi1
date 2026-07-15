<?php

namespace App\Http\Controllers;

use App\Rules\PubliclyRoutableUrl;
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
            'body' => 'nullable|string',
        ]);

        $url = $validated['url'];
        $method = strtoupper($validated['method']);
        $headers = collect($validated['headers'] ?? [])->filter(function($value, $key) {
            // Filter out host and content-length headers to avoid conflict
            $k = strtolower($key);
            return !in_array($k, ['host', 'content-length']);
        })->toArray();
        $body = $validated['body'] ?? null;

        $startTime = microtime(true);

        try {
            $pendingRequest = Http::withHeaders($headers)->withoutVerifying();
            
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

            return response()->json([
                'status' => 500,
                'headers' => [],
                'body' => 'Proxy Error: ' . $e->getMessage(),
                'time_ms' => $timeTakenMs,
            ], 500);
        }
    }
}
