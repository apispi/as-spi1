<?php

namespace Tests\Feature;

use App\Models\McpProxy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpTrafficSynthesisTest extends TestCase
{
    use RefreshDatabase;

    private function proxyWithTraffic(User $user): McpProxy
    {
        $proxy = $user->mcpProxies()->create([
            'name' => 'Acme recorder', 'token' => McpProxy::generateToken(),
            'upstream_url' => 'https://mcp.acme.example.com/tools',
        ]);

        // The server declares "search" taking only {query}.
        $proxy->exchanges()->create([
            'method' => 'tools/list', 'status' => 200,
            'response' => ['result' => ['tools' => [
                ['name' => 'search', 'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]]],
            ]]],
        ]);

        // But agents actually send {query, limit} and get back {results[], nextCursor}.
        $proxy->exchanges()->create([
            'method' => 'tools/call', 'status' => 200,
            'request' => ['params' => ['name' => 'search', 'arguments' => ['query' => 'shoes', 'limit' => 10]]],
            'response' => ['result' => ['results' => [['id' => 1]], 'nextCursor' => 'abc']],
        ]);
        $proxy->exchanges()->create([
            'method' => 'tools/call', 'status' => 200,
            'request' => ['params' => ['name' => 'search', 'arguments' => ['query' => 'hats', 'limit' => 5]]],
            'response' => ['result' => ['results' => [], 'nextCursor' => 'def']],
        ]);

        // A tool that was called but never declared in tools/list.
        $proxy->exchanges()->create([
            'method' => 'tools/call', 'status' => 200,
            'request' => ['params' => ['name' => 'secret_admin', 'arguments' => ['token' => 'x']]],
            'response' => ['result' => ['ok' => true]],
        ]);

        return $proxy;
    }

    public function test_it_infers_observed_input_and_output_from_real_calls(): void
    {
        $user = User::factory()->create();
        $proxy = $this->proxyWithTraffic($user);

        $data = $this->actingAs($user)->getJson("/api/mcp-proxies/{$proxy->id}/synthesize")
            ->assertOk()->json();

        $search = collect($data['tools'])->firstWhere('name', 'search');

        // Declared only {query}; observed input includes {limit} agents really send.
        $this->assertArrayHasKey('query', $search['declared_input_schema']['properties']);
        $this->assertArrayHasKey('limit', $search['observed_input_schema']['properties']);
        $this->assertSame('integer', $search['observed_input_schema']['properties']['limit']['type']);

        // Output schema learned from the responses.
        $this->assertArrayHasKey('results', $search['observed_output_schema']['properties']);
        $this->assertArrayHasKey('nextCursor', $search['observed_output_schema']['properties']);
        $this->assertSame(2, $search['call_count']);
    }

    public function test_it_surfaces_a_tool_that_was_called_but_never_declared(): void
    {
        $user = User::factory()->create();
        $proxy = $this->proxyWithTraffic($user);

        $data = $this->actingAs($user)->getJson("/api/mcp-proxies/{$proxy->id}/synthesize")->json();

        $secret = collect($data['tools'])->firstWhere('name', 'secret_admin');
        $this->assertTrue($secret['only_observed']);
        $this->assertNull($secret['declared_input_schema']);
    }

    public function test_a_user_cannot_synthesize_another_users_recorder(): void
    {
        $owner = User::factory()->create();
        $proxy = $owner->mcpProxies()->create([
            'name' => 'Theirs', 'token' => McpProxy::generateToken(),
            'upstream_url' => 'https://mcp.example.com/tools',
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson("/api/mcp-proxies/{$proxy->id}/synthesize")
            ->assertStatus(404);
    }
}
