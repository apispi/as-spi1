<?php

namespace App\Http\Controllers;

use App\Models\RequestHistory;
use App\Rules\PubliclyRoutableUrl;
use App\Services\A2a\A2aClient;
use App\Services\Auth\RequestAuthenticator;
use Illuminate\Http\Request;
use Throwable;

class A2aTestController extends Controller
{
    public function test(Request $request)
    {
        $validated = $request->validate([
            'url' => ['required', 'url', new PubliclyRoutableUrl],
            'method' => 'required|string',
            'params' => 'nullable|array',
            'headers' => 'nullable|array',
        ] + RequestAuthenticator::rules());

        $headers = collect($validated['headers'] ?? [])->filter(function ($value, $key) {
            return ! in_array(strtolower($key), ['host', 'content-length']);
        })->toArray();

        // The auth helper applies here exactly as it does to a REST request,
        // so an MCP/A2A server behind a token authenticates the same way in
        // the tester as it does inside a collection run.
        $authed = (new RequestAuthenticator)->apply($validated['auth'] ?? null, $headers, $validated['url']);
        $headers = $authed['headers'];
        $validated['url'] = $authed['url'];

        $client = new A2aClient($validated['url'], null, $headers);

        $startTime = microtime(true);

        try {
            $result = $validated['method'] === 'agent-card'
                ? $client->getAgentCard()
                : $client->request($validated['method'], $validated['params'] ?? []);

            $timeTakenMs = round((microtime(true) - $startTime) * 1000);

            RequestHistory::record($request->user()->id, [
                'protocol' => 'a2a',
                'method' => $validated['method'],
                'url' => $validated['url'],
                'params' => $validated['params'] ?? null,
                'status' => 200,
                'time_ms' => $timeTakenMs,
            ]);

            return response()->json([
                'status' => 200,
                'headers' => [],
                'body' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                'time_ms' => $timeTakenMs,
                'request_payload' => json_encode($validated['params'] ?? [], JSON_PRETTY_PRINT),
                'request_headers' => $headers,
            ]);
        } catch (Throwable $e) {
            $timeTakenMs = round((microtime(true) - $startTime) * 1000);

            RequestHistory::record($request->user()->id, [
                'protocol' => 'a2a',
                'method' => $validated['method'],
                'url' => $validated['url'],
                'params' => $validated['params'] ?? null,
                'status' => null,
                'time_ms' => $timeTakenMs,
            ]);

            return response()->json([
                'status' => 500,
                'headers' => [],
                'body' => 'A2A Error: '.$e->getMessage(),
                'time_ms' => $timeTakenMs,
            ], 500);
        }
    }
}
