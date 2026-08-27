<?php

namespace App\Http\Controllers;

use App\Models\McpProxy;
use App\Services\Mcp\McpSecurityScanner;
use App\Services\Security\SsrfException;
use App\Services\Security\SsrfGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The public relay half of the flight recorder: ANY method on
 * /mcp-proxy/{token} forwards to the proxy's upstream MCP server and records
 * the exchange on the way through.
 *
 * Faithful pass-through first: the body, the MCP session headers, and the
 * caller's Authorization all travel unmodified in both directions, so
 * sessions and upstream auth keep working — but the Authorization value is
 * redacted from what is stored. The upstream address is re-pinned on every
 * relay, since the owner could have re-pointed the DNS since creation.
 *
 * Every JSON response is run through the injection scanner as it passes:
 * a tool description or result that tries to hijack the agent flags the
 * exchange, giving live detection on real traffic rather than a one-off scan.
 */
class McpProxyRelayController extends Controller
{
    /** Stored payload cap per side; larger bodies are recorded truncated. */
    public const MAX_STORED = 65536;

    private const RELAY_TIMEOUT = 60;

    public function relay(Request $request, string $token)
    {
        $proxy = McpProxy::where('token', $token)->where('is_enabled', true)->first();

        if (! $proxy) {
            return response()->json(['ok' => false], 404);
        }

        try {
            $pinned = (new SsrfGuard)->pinnedOptions($proxy->upstream_url);
        } catch (SsrfException $e) {
            return response()->json(['error' => 'Upstream refused: '.$e->getMessage()], 502);
        }

        $headers = array_filter([
            'Content-Type' => $request->header('Content-Type', 'application/json'),
            'Accept' => $request->header('Accept', 'application/json, text/event-stream'),
            'Authorization' => $request->header('Authorization'),
            'Mcp-Session-Id' => $request->header('Mcp-Session-Id'),
            'Mcp-Protocol-Version' => $request->header('Mcp-Protocol-Version'),
        ]);

        $body = (string) $request->getContent();
        $started = microtime(true);

        try {
            $upstream = Http::withHeaders($headers)
                ->withOptions(['allow_redirects' => false] + $pinned)
                ->timeout(self::RELAY_TIMEOUT)
                ->send($request->method(), $proxy->upstream_url, $body === '' ? [] : ['body' => $body]);
        } catch (Throwable $e) {
            $this->record($proxy, $request, $body, null, null, (int) round((microtime(true) - $started) * 1000), $e->getMessage());

            return response()->json(['error' => 'Upstream unreachable: '.$e->getMessage()], 502);
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $this->record($proxy, $request, $body, $upstream->body(), $upstream->status(), $durationMs);

        $proxy->forceFill(['last_used_at' => now()])->save();

        // Relay the response as-is, session headers included.
        return response($upstream->body(), $upstream->status())
            ->withHeaders(array_filter([
                'Content-Type' => $upstream->header('Content-Type'),
                'Mcp-Session-Id' => $upstream->header('Mcp-Session-Id'),
                'Mcp-Protocol-Version' => $upstream->header('Mcp-Protocol-Version'),
            ]));
    }

    private function record(
        McpProxy $proxy,
        Request $request,
        string $requestBody,
        ?string $responseBody,
        ?int $status,
        int $durationMs,
        ?string $error = null,
    ): void {
        $requestJson = json_decode($requestBody, true);
        $responseJson = $responseBody !== null ? json_decode($responseBody, true) : null;

        // Non-POST verbs of the transport (SSE stream open, session delete)
        // have no JSON-RPC method of their own.
        $method = is_array($requestJson) && isset($requestJson['method'])
            ? (string) $requestJson['method']
            : $request->method().' '.($request->method() === 'GET' ? 'stream' : 'session');

        [$flagged, $summary] = $this->scanResponse($method, $responseJson);

        $proxy->exchanges()->create([
            'method' => $method,
            'request' => $this->bounded($requestJson, $requestBody),
            'response' => $error !== null
                ? ['relay_error' => $error]
                : $this->bounded($responseJson, $responseBody ?? ''),
            'status' => $status,
            'duration_ms' => $durationMs,
            'flagged' => $flagged,
            'flag_summary' => $summary,
        ]);

        $this->trim($proxy);
    }

    /**
     * Run the response through the same injection scanner the security page
     * uses, treating the whole payload as agent-readable text — because it is.
     */
    private function scanResponse(string $method, mixed $responseJson): array
    {
        if (! is_array($responseJson)) {
            return [false, null];
        }

        $scan = McpSecurityScanner::scan([[
            'name' => $method,
            'description' => json_encode($responseJson, JSON_UNESCAPED_SLASHES),
        ]]);

        if (empty($scan['findings'])) {
            return [false, null];
        }

        $top = $scan['findings'][0];

        return [true, mb_substr(($top['title'] ?? 'Suspicious content').' ('.($scan['risk'] ?? '?').' risk)', 0, 255)];
    }

    /**
     * Store parsed JSON when it fits, a truncation marker when it does not.
     * The Authorization header never reaches storage — it was stripped before
     * this point by simply not being part of the recorded payload.
     */
    private function bounded(mixed $json, string $raw): ?array
    {
        if (strlen($raw) > self::MAX_STORED) {
            return [
                'truncated' => true,
                'bytes' => strlen($raw),
                'head' => mb_substr($raw, 0, 2000),
            ];
        }

        if (is_array($json)) {
            return $json;
        }

        return $raw === '' ? null : ['raw' => $raw];
    }

    private function trim(McpProxy $proxy): void
    {
        $cutoff = $proxy->exchanges()->skip(McpProxy::RETENTION)->value('id');

        if ($cutoff !== null) {
            $proxy->exchanges()->where('id', '<=', $cutoff)->delete();
        }
    }
}
