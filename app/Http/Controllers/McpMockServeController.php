<?php

namespace App\Http\Controllers;

use App\Models\McpMock;
use Illuminate\Http\Request;

/**
 * Serves a mock MCP server at /mcp-mock/{token} over Streamable HTTP.
 *
 * Answers the handshake, tools/list, and tools/call from the mock's stored
 * definition — so an agent can be developed against a stand-in before the real
 * server exists. Unauthenticated: the token is the credential, like the other
 * public routes. Structurally mirrors the real MCP gateway.
 */
class McpMockServeController extends Controller
{
    public const PROTOCOL_VERSION = '2025-06-18';

    public function serve(Request $request, string $token)
    {
        $mock = McpMock::where('token', $token)->where('is_enabled', true)->first();

        if (! $mock) {
            return response()->json(['ok' => false], 404);
        }

        // Non-POST verbs of the transport (SSE open, session delete): 202.
        if ($request->method() !== 'POST') {
            return response()->noContent(202);
        }

        $message = $request->json()->all();

        if (($message['jsonrpc'] ?? null) !== '2.0' || empty($message['method'])) {
            return $this->error(null, -32600, 'Invalid Request.');
        }

        $id = $message['id'] ?? null;
        if ($id === null) {
            return response()->noContent(202); // notification
        }

        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        $result = match ($message['method']) {
            'initialize' => [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => ['tools' => (object) []],
                'serverInfo' => ['name' => $mock->server_name, 'version' => $mock->server_version],
            ],
            'ping' => (object) [],
            'tools/list' => ['tools' => $this->toolList($mock)],
            'tools/call' => $this->callTool($mock, $params, $id),
            default => null,
        };

        // callTool returns a full JsonResponse on error (its own id handling).
        if ($result instanceof \Illuminate\Http\JsonResponse) {
            return $result;
        }

        if ($result === null) {
            return $this->error($id, -32601, 'Method not found: '.$message['method']);
        }

        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    /**
     * The declared surface — never leak an internal `response` field.
     */
    private function toolList(McpMock $mock): array
    {
        return array_map(fn ($t) => array_filter([
            'name' => $t['name'] ?? 'unnamed',
            'description' => $t['description'] ?? null,
            'inputSchema' => $t['inputSchema'] ?? ['type' => 'object', 'properties' => (object) []],
        ], fn ($v) => $v !== null), $mock->tools ?? []);
    }

    private function callTool(McpMock $mock, array $params, mixed $id)
    {
        $tool = $mock->findTool((string) ($params['name'] ?? ''));

        if (! $tool) {
            return $this->error($id, -32602, 'Unknown tool: '.($params['name'] ?? ''));
        }

        // A tool's canned `response` is returned verbatim as the tools/call
        // result; absent, a minimal text result stands in.
        $response = $tool['response'] ?? null;

        if (! is_array($response)) {
            $response = ['content' => [['type' => 'text', 'text' => 'Mock response for '.$tool['name']]]];
        }

        return $response;
    }

    private function error(mixed $id, int $code, string $message)
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    }
}
