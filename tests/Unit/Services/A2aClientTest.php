<?php

namespace Tests\Unit\Services;

use App\Services\A2a\A2aClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class A2aClientTest extends TestCase
{
    public function test_get_agent_card_uses_current_spec_path(): void
    {
        Http::fake([
            'agent.test/.well-known/agent-card.json' => Http::response(['name' => 'Demo Agent'], 200),
        ]);

        $client = new A2aClient('https://agent.test/a2a');
        $card = $client->getAgentCard();

        $this->assertSame('Demo Agent', $card['name']);
    }

    public function test_get_agent_card_falls_back_to_legacy_path(): void
    {
        Http::fake([
            'agent.test/.well-known/agent-card.json' => Http::response('', 404),
            'agent.test/.well-known/agent.json' => Http::response(['name' => 'Legacy Agent'], 200),
        ]);

        $client = new A2aClient('https://agent.test/a2a');
        $card = $client->getAgentCard();

        $this->assertSame('Legacy Agent', $card['name']);
    }

    public function test_get_agent_card_throws_when_neither_path_resolves(): void
    {
        Http::fake([
            'agent.test/*' => Http::response('', 404),
        ]);

        $client = new A2aClient('https://agent.test/a2a');

        $this->expectException(RuntimeException::class);
        $client->getAgentCard();
    }

    public function test_send_message_posts_json_rpc_message_send(): void
    {
        Http::fake([
            'agent.test/a2a' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => ['taskId' => 'task-1', 'status' => 'submitted'],
            ], 200),
        ]);

        $client = new A2aClient('https://agent.test/a2a');
        $result = $client->sendMessage(['role' => 'user', 'parts' => [['text' => 'hi']]]);

        $this->assertSame('task-1', $result['taskId']);

        Http::assertSent(function ($request) {
            return $request['method'] === 'message/send';
        });
    }

    public function test_request_throws_on_json_rpc_error(): void
    {
        Http::fake([
            'agent.test/a2a' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => ['code' => -32602, 'message' => 'Invalid params'],
            ], 200),
        ]);

        $client = new A2aClient('https://agent.test/a2a');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid params');

        $client->getTask('missing-task');
    }
}
