<?php

namespace App\Http\Controllers;

use App\Models\McpProxy;
use App\Services\Mcp\McpPolicyEngine;
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
 * Every JSON response is run through the injection scanner as it passes.
 * A proxy may also carry a firewall POLICY: the relay enforces it inline —
 * blocking a tool call, redacting secrets in arguments before they leave, or
 * withholding an injection-flagged response before it reaches the agent. Each
 * enforcement is recorded on the exchange.
 */
class McpProxyRelayController extends Controller
{
    /** Stored payload cap per side; larger bodies are recorded truncated. */
    public const MAX_STORED = 65536;

    private const RELAY_TIMEOUT = 60;

    public function relay(Request $request, McpPolicyEngine $firewall, string $token)
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
        $requestJson = json_decode($body, true);
        $policy = $proxy->policy ?? [];

        // --- Firewall: request side ---------------------------------------
        $reqDecision = $firewall->evaluateRequest($policy, is_array($requestJson) ? $requestJson : null);

        if ($reqDecision['action'] === 'block') {
            // Never forwarded. The agent gets a JSON-RPC error in its place.
            $rpcId = $requestJson['id'] ?? null;
            $error = $this->rpcError($rpcId, -32001, 'Spi policy: '.$reqDecision['note']);
            $this->record($proxy, $request, $body, json_encode($error), 200, 0, null, [
                'action' => 'blocked_request', 'note' => $reqDecision['note'], 'rule' => $reqDecision['rule'], 'redactions' => 0,
            ]);

            return response()->json($error);
        }

        // Redacted arguments are what actually leave (and what we store).
        if ($reqDecision['redactions'] > 0 && is_array($requestJson)) {
            $requestJson['params']['arguments'] = $reqDecision['arguments'];
            $body = json_encode($requestJson, JSON_UNESCAPED_SLASHES);
        }

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
        $upstreamBody = $upstream->body();
        $responseJson = json_decode($upstreamBody, true);

        // --- Firewall: response side --------------------------------------
        [$flagged] = $this->scanResponse('', is_array($responseJson) ? $responseJson : null);
        $respDecision = $firewall->evaluateResponse($policy, is_array($responseJson) ? $responseJson : null, $flagged);

        $enforcement = null;
        $returnBody = $upstreamBody;

        if ($respDecision['action'] === 'block') {
            $safe = $this->rpcError($requestJson['id'] ?? null, -32002, 'Spi policy: '.$respDecision['note']);
            $returnBody = json_encode($safe);
            $enforcement = ['action' => 'blocked_response', 'note' => $respDecision['note'], 'rule' => $respDecision['rule'], 'redactions' => 0];
        } elseif ($respDecision['action'] === 'redact') {
            $returnBody = json_encode($respDecision['result'], JSON_UNESCAPED_SLASHES);
            $enforcement = ['action' => 'redacted_response', 'note' => $respDecision['note'], 'rule' => null, 'redactions' => $respDecision['redactions']];
        } elseif ($reqDecision['redactions'] > 0) {
            $enforcement = ['action' => 'redacted_request', 'note' => $reqDecision['note'], 'rule' => null, 'redactions' => $reqDecision['redactions']];
        }

        // Record what actually flowed (redactions included, secrets excluded).
        $this->record($proxy, $request, $body, $returnBody, $upstream->status(), $durationMs, null, $enforcement);

        $proxy->forceFill(['last_used_at' => now()])->save();

        return response($returnBody, $upstream->status())
            ->withHeaders(array_filter([
                'Content-Type' => $upstream->header('Content-Type'),
                'Mcp-Session-Id' => $upstream->header('Mcp-Session-Id'),
                'Mcp-Protocol-Version' => $upstream->header('Mcp-Protocol-Version'),
            ]));
    }

    private function rpcError(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    private function record(
        McpProxy $proxy,
        Request $request,
        string $requestBody,
        ?string $responseBody,
        ?int $status,
        int $durationMs,
        ?string $error = null,
        ?array $enforcement = null,
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
            'enforcement' => $enforcement,
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
