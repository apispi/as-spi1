<?php

namespace Tests\Feature;

use App\Models\McpMock;
use App\Models\McpProxy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpMockServerTest extends TestCase
{
    use RefreshDatabase;

    private function makeMock(User $user, array $tools = [], array $attrs = []): McpMock
    {
        return $user->mcpMocks()->create(array_merge([
            'name' => 'Weather mock', 'token' => McpMock::generateToken(),
            'server_name' => 'WeatherMock', 'server_version' => '2.0',
            'tools' => $tools,
        ], $attrs));
    }

    private function rpc(string $token, string $method, array $params = [], ?int $id = 1)
    {
        $msg = ['jsonrpc' => '2.0', 'method' => $method, 'params' => (object) $params];
        if ($id !== null) {
            $msg['id'] = $id;
        }

        return $this->postJson("/mcp-mock/{$token}", $msg);
    }

    public function test_it_serves_the_mcp_handshake(): void
    {
        $mock = $this->makeMock(User::factory()->create());

        $this->rpc($mock->token, 'initialize')
            ->assertOk()
            ->assertJsonPath('result.protocolVersion', '2025-06-18')
            ->assertJsonPath('result.serverInfo.name', 'WeatherMock')
            ->assertJsonPath('result.serverInfo.version', '2.0');

        $this->rpc($mock->token, 'notifications/initialized', [], null)->assertStatus(202);
    }

    public function test_tools_list_declares_tools_without_leaking_the_canned_response(): void
    {
        $mock = $this->makeMock(User::factory()->create(), [[
            'name' => 'get_weather',
            'description' => 'Current weather',
            'inputSchema' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
            'response' => ['content' => [['type' => 'text', 'text' => 'SECRET INTERNAL']]],
        ]]);

        $tools = $this->rpc($mock->token, 'tools/list')->assertOk()->json('result.tools');

        $this->assertSame('get_weather', $tools[0]['name']);
        $this->assertArrayHasKey('inputSchema', $tools[0]);
        // The canned response is an internal detail of tools/call, not the listing.
        $this->assertArrayNotHasKey('response', $tools[0]);
    }

    public function test_tools_call_returns_the_canned_response(): void
    {
        $mock = $this->makeMock(User::factory()->create(), [[
            'name' => 'get_weather',
            'response' => ['content' => [['type' => 'text', 'text' => 'Sydney: 22°C, sunny']]],
        ]]);

        $this->rpc($mock->token, 'tools/call', ['name' => 'get_weather', 'arguments' => ['city' => 'Sydney']])
            ->assertOk()
            ->assertJsonPath('result.content.0.text', 'Sydney: 22°C, sunny');
    }

    public function test_unknown_tool_and_method_return_proper_errors(): void
    {
        $mock = $this->makeMock(User::factory()->create());

        $this->rpc($mock->token, 'tools/call', ['name' => 'nope'])->assertOk()->assertJsonPath('error.code', -32602);
        $this->rpc($mock->token, 'this/doesNotExist')->assertOk()->assertJsonPath('error.code', -32601);
    }

    public function test_a_disabled_or_unknown_token_is_a_404(): void
    {
        $this->rpc(str_repeat('x', 40), 'initialize')->assertStatus(404);

        $mock = $this->makeMock(User::factory()->create(), [], ['is_enabled' => false]);
        $this->rpc($mock->token, 'initialize')->assertStatus(404);
    }

    public function test_a_mock_can_be_seeded_from_recorded_traffic(): void
    {
        $user = User::factory()->create();
        $proxy = $user->mcpProxies()->create([
            'name' => 'Acme recorder', 'token' => McpProxy::generateToken(),
            'upstream_url' => 'https://mcp.acme.example.com/tools',
        ]);
        $proxy->exchanges()->create([
            'method' => 'tools/list', 'status' => 200,
            'response' => ['result' => ['tools' => [
                ['name' => 'search', 'description' => 'Find things', 'inputSchema' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]]],
            ]]],
        ]);
        $proxy->exchanges()->create([
            'method' => 'tools/call', 'status' => 200,
            'request' => ['params' => ['name' => 'search', 'arguments' => ['q' => 'ada', 'limit' => 5]]],
            'response' => ['result' => ['content' => [['type' => 'text', 'text' => 'found: ada']]]],
        ]);

        $created = $this->actingAs($user)->postJson("/api/mcp-mocks/from-recorder/{$proxy->id}")
            ->assertStatus(201)
            ->assertJsonPath('tool_count', 1);

        // The observed input (q + limit) and the real sample response were seeded.
        $mock = McpMock::first();
        $tool = $mock->tools[0];
        $this->assertSame('search', $tool['name']);
        $this->assertArrayHasKey('limit', $tool['inputSchema']['properties']);

        // And the seeded mock actually serves that response.
        $this->rpc($mock->token, 'tools/call', ['name' => 'search'])
            ->assertOk()->assertJsonPath('result.content.0.text', 'found: ada');
    }

    public function test_crud_is_workspace_scoped_and_validated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/mcp-mocks', [
            'name' => 'M', 'tools' => [['name' => 'bad name!', 'response' => []]],
        ])->assertStatus(422)->assertJsonValidationErrors(['tools.0.name']);

        $created = $this->actingAs($user)->postJson('/api/mcp-mocks', [
            'name' => 'M', 'tools' => [['name' => 'ok_tool', 'response' => ['content' => []]]],
        ])->assertStatus(201);

        $this->assertStringContainsString('/mcp-mock/', $created->json('url'));

        // Another user (different workspace) cannot see or edit it.
        $this->actingAs(User::factory()->create())
            ->getJson('/api/mcp-mocks/'.$created->json('id'))->assertStatus(404);
    }

    public function test_management_requires_authentication(): void
    {
        $this->getJson('/api/mcp-mocks')->assertStatus(401);
    }
}
