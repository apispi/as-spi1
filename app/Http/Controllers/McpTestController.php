<?php

namespace App\Http\Controllers;

use App\Rules\PubliclyRoutableUrl;
use App\Services\Mcp\McpClient;
use Illuminate\Http\Request;
use Throwable;

class McpTestController extends Controller
{
    public function test(Request $request)
    {
        $validated = $request->validate([
            'url' => ['required', 'url', new PubliclyRoutableUrl],
            'method' => 'required|string',
            'params' => 'nullable|array',
            'headers' => 'nullable|array',
        ]);

        $headers = collect($validated['headers'] ?? [])->filter(function ($value, $key) {
            return ! in_array(strtolower($key), ['host', 'content-length']);
        })->toArray();

        $client = new McpClient($validated['url'], null, $headers);

        $startTime = microtime(true);

        try {
            $init = $client->initialize();

            $result = $validated['method'] === 'initialize'
                ? $init
                : $client->request($validated['method'], $validated['params'] ?? []);

            $timeTakenMs = round((microtime(true) - $startTime) * 1000);

            return response()->json([
                'status' => 200,
                'headers' => array_filter([
                    'Mcp-Session-Id' => $client->sessionId(),
                    'Mcp-Protocol-Version' => $client->protocolVersion(),
                    'Server' => trim(($init['serverInfo']['name'] ?? '').' '.($init['serverInfo']['version'] ?? '')),
                ]),
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
                'body' => 'MCP Error: '.$e->getMessage(),
                'time_ms' => $timeTakenMs,
            ], 500);
        }
    }
}
