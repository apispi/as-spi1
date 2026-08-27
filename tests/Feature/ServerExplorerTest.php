<?php

namespace Tests\Feature;

use App\Models\InspectionReport;
use App\Models\User;
use App\Services\Scx\ScxClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ServerExplorerTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['scx_api_key' => 'sk-test', 'scx_model' => 'scx-ai']);
    }

    public function test_it_explores_with_read_only_tools_and_records_capabilities(): void
    {
        Http::fake([
            'mcp.test/*' => Http::sequence()
                ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2025-06-18', 'serverInfo' => ['name' => 'demo', 'version' => '1']]], 200)
                ->push('', 202)
                ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [
                    ['name' => 'search_users', 'description' => 'Find users', 'inputSchema' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]]],
                    ['name' => 'delete_user', 'description' => 'Remove a user'],
                ]]], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 3, 'result' => ['content' => [['type' => 'text', 'text' => 'found: ada@x.com']]]], 200),
            ScxClient::ENDPOINT => Http::sequence()
                ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
                    'id' => 'c1', 'type' => 'function', 'function' => ['name' => 'search_users', 'arguments' => '{"q":"ada"}'],
                ]]]]]], 200)
                ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => "Found ada@x.com. Done."]]]], 200),
        ]);

        $response = $this->actingAs($this->user())->postJson('/api/explore', [
            'url' => 'https://mcp.test/mcp', 'goal' => "find ada's email",
        ])->assertOk();

        $response->assertJsonPath('completed', true)
            ->assertJsonPath('tools_attempted', ['search_users']);

        // The destructive tool the server exposes is inventoried even though
        // the agent did not need it.
        $this->assertContains('delete_user', $response->json('capabilities.destructive_tools'));
        $this->assertDatabaseHas('inspection_reports', ['type' => 'exploration']);
    }

    public function test_safe_mode_blocks_a_destructive_call_without_sending_it(): void
    {
        // The model decides to call delete_user; safe mode refuses, so the MCP
        // server is never asked to run it — the sequence has no tool-call push.
        Http::fake([
            'mcp.test/*' => Http::sequence()
                ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2025-06-18', 'serverInfo' => ['name' => 'demo', 'version' => '1']]], 200)
                ->push('', 202)
                ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [
                    ['name' => 'delete_user', 'description' => 'Remove a user', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]],
                ]]], 200),
            ScxClient::ENDPOINT => Http::sequence()
                ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
                    'id' => 'c1', 'type' => 'function', 'function' => ['name' => 'delete_user', 'arguments' => '{"id":7}'],
                ]]]]]], 200)
                ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => 'That would require a destructive action, so I stopped.']]]], 200),
        ]);

        $response = $this->actingAs($this->user())->postJson('/api/explore', [
            'url' => 'https://mcp.test/mcp', 'goal' => 'remove user 7', 'safe_mode' => true,
        ])->assertOk();

        $response->assertJsonPath('blocked_attempts.0.name', 'delete_user')
            ->assertJsonPath('blocked_attempts.0.arguments.id', 7);

        // Exactly three MCP calls were made (init, initialized, tools/list) —
        // none of them the delete.
        Http::assertSentCount(5); // 3 MCP + 2 SCX
    }

    public function test_it_needs_an_scx_key(): void
    {
        $user = User::factory()->create(['scx_api_key' => null]);

        $this->actingAs($user)->postJson('/api/explore', [
            'url' => 'https://mcp.test/mcp', 'goal' => 'x',
        ])->assertStatus(400);
    }

    public function test_it_rejects_an_internal_url(): void
    {
        $this->actingAs($this->user())->postJson('/api/explore', [
            'url' => 'http://169.254.169.254/mcp', 'goal' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors(['url']);
    }

    public function test_it_requires_authentication(): void
    {
        $this->postJson('/api/explore', ['url' => 'https://mcp.test/mcp', 'goal' => 'x'])->assertStatus(401);
    }
}
