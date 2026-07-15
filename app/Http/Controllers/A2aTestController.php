<?php

namespace App\Http\Controllers;

use App\Services\A2a\A2aClient;
use Illuminate\Http\Request;
use Throwable;

class A2aTestController extends Controller
{
    public function test(Request $request)
    {
        $validated = $request->validate([
            'url' => 'required|url',
            'method' => 'required|string',
            'params' => 'nullable|array',
            'headers' => 'nullable|array',
        ]);

        $headers = collect($validated['headers'] ?? [])->filter(function ($value, $key) {
            return ! in_array(strtolower($key), ['host', 'content-length']);
        })->toArray();

        $client = new A2aClient($validated['url'], null, $headers);

        $startTime = microtime(true);

        try {
            $result = $validated['method'] === 'agent-card'
                ? $client->getAgentCard()
                : $client->request($validated['method'], $validated['params'] ?? []);

            $timeTakenMs = round((microtime(true) - $startTime) * 1000);

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

            return response()->json([
                'status' => 500,
                'headers' => [],
                'body' => 'A2A Error: '.$e->getMessage(),
                'time_ms' => $timeTakenMs,
            ], 500);
        }
    }
}
