<?php

namespace Tests\Unit\Services;

use App\Services\Mcp\McpClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class McpClientTest extends TestCase
{
    public function test_initialize_performs_handshake_and_sends_initialized_notification(): void
    {
        Http::fake([
            'mcp.test/*' => Http::sequence()
                ->push([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'protocolVersion' => '2025-06-18',
                        'serverInfo' => ['name' => 'demo-mcp', 'version' => '1.0'],
                    ],
                ], 200, ['Mcp-Session-Id' => 'sess-123'])
                ->push('', 202),
        ]);

        $client = new McpClient('https://mcp.test/mcp');
        $result = $client->initialize();

        $this->assertSame('2025-06-18', $result['protocolVersion']);
        $this->assertSame('sess-123', $client->sessionId());

        Http::assertSentCount(2);
    }

    public function test_list_tools_returns_result_and_reuses_session_id(): void
    {
        Http::fake([
            'mcp.test/*' => Http::sequence()
                ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2025-06-18']], 200, ['Mcp-Session-Id' => 'sess-abc'])
                ->push('', 202)
                ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [['name' => 'echo']]]], 200),
        ]);

        $client = new McpClient('https://mcp.test/mcp');
        $client->initialize();
        $tools = $client->listTools();

        $this->assertSame([['name' => 'echo']], $tools['tools']);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Mcp-Session-Id', 'sess-abc');
        });
    }

    public function test_request_throws_on_json_rpc_error(): void
    {
        Http::fake([
            'mcp.test/*' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => ['code' => -32601, 'message' => 'Method not found'],
            ], 200),
        ]);

        $client = new McpClient('https://mcp.test/mcp');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Method not found');

        $client->request('tools/call', ['name' => 'missing']);
    }

    public function test_request_parses_sse_stream_response(): void
    {
        $sse = "event: message\ndata: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{\"ok\":true}}\n\n";

        Http::fake([
            'mcp.test/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream']),
        ]);

        $client = new McpClient('https://mcp.test/mcp');
        $result = $client->request('ping');

        $this->assertSame(['ok' => true], $result);
    }

    public function test_extra_headers_are_sent(): void
    {
        Http::fake([
            'mcp.test/*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => []], 200),
        ]);

        $client = new McpClient('https://mcp.test/mcp', null, ['Authorization' => 'Bearer secret']);
        $client->request('ping');

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer secret');
        });
    }
}
