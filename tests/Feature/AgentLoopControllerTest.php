<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\User;
use App\Services\Scx\ScxClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentLoopControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function connector(): CatalogItem
    {
        return CatalogItem::create([
            'type' => 'connector',
            'name' => 'Demo MCP',
            'slug' => 'demo-mcp',
            'metadata' => ['endpoint' => 'https://mcp.test/mcp', 'protocol' => 'mcp'],
        ]);
    }

    public function test_requires_admin(): void
    {
        $connector = $this->connector();
        $this->postJson("/api/admin/catalog/{$connector->id}/agent-loop", ['goal' => 'x'])->assertStatus(401);

        $user = User::factory()->create(['is_admin' => false, 'scx_api_key' => 'k']);
        $this->actingAs($user)->postJson("/api/admin/catalog/{$connector->id}/agent-loop", ['goal' => 'x'])
            ->assertStatus(403);
    }

    public function test_requires_scx_key(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $connector = $this->connector();

        $this->actingAs($admin)->postJson("/api/admin/catalog/{$connector->id}/agent-loop", ['goal' => 'x'])
            ->assertStatus(400);
    }

    public function test_runs_a_tool_calling_loop_and_records_the_trace(): void
    {
        Http::fake([
            'mcp.test/*' => Http::sequence()
                ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2025-06-18', 'serverInfo' => ['name' => 'demo', 'version' => '1']]], 200)
                ->push('', 202) // initialized notification
                ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [
                    ['name' => 'echo', 'description' => 'Echoes text', 'inputSchema' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']]]],
                ]]], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 3, 'result' => ['content' => [['type' => 'text', 'text' => 'hi there']]]], 200),
            ScxClient::ENDPOINT => Http::sequence()
                ->push(['choices' => [['message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'echo', 'arguments' => '{"text":"hi"}'],
                    ]],
                ]]]], 200)
                ->push(['choices' => [['message' => [
                    'role' => 'assistant',
                    'content' => 'The tool echoed: hi there. Goal accomplished.',
                ]]]], 200),
        ]);

        $admin = User::factory()->create(['is_admin' => true, 'scx_api_key' => 'sk-test']);
        $connector = $this->connector();

        $response = $this->actingAs($admin)->postJson("/api/admin/catalog/{$connector->id}/agent-loop", [
            'goal' => 'Echo the word hi',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('completed', true)
            ->assertJsonPath('tool_call_count', 1)
            ->assertJsonPath('stop_reason', 'completed');

        $steps = $response->json('steps');
        $this->assertCount(2, $steps);
        $this->assertSame('echo', $steps[0]['tool_calls'][0]['name']);
        $this->assertStringContainsString('Goal accomplished', $response->json('final_answer'));
    }
}
