<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class McpTestControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_reach_the_endpoint(): void
    {
        $response = $this->postJson('/api/mcp/test', [
            'url' => 'https://mcp.test/mcp',
            'method' => 'tools/list',
        ]);

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_test_an_mcp_server(): void
    {
        Http::fake([
            'mcp.test/*' => Http::sequence()
                ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => [
                    'protocolVersion' => '2025-06-18',
                    'serverInfo' => ['name' => 'demo-mcp', 'version' => '1.0'],
                ]], 200, ['Mcp-Session-Id' => 'sess-1'])
                ->push('', 202)
                ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [['name' => 'echo']]]], 200),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/mcp/test', [
            'url' => 'https://mcp.test/mcp',
            'method' => 'tools/list',
            'params' => [],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 200)
            ->assertJsonPath('headers.Mcp-Session-Id', 'sess-1');

        $this->assertStringContainsString('echo', $response->json('body'));
    }

    public function test_returns_500_when_mcp_server_errors(): void
    {
        Http::fake([
            'mcp.test/*' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => ['code' => -32601, 'message' => 'Method not found'],
            ], 200),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/mcp/test', [
            'url' => 'https://mcp.test/mcp',
            'method' => 'tools/list',
        ]);

        $response->assertStatus(500)->assertJsonPath('status', 500);
    }

    public function test_validates_required_fields(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/mcp/test', []);

        $response->assertStatus(422)->assertJsonValidationErrors(['url', 'method']);
    }
}
