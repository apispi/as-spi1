<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConnectorSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    protected function connector(array $meta = []): CatalogItem
    {
        return CatalogItem::create([
            'type' => 'connector',
            'name' => 'Demo MCP',
            'slug' => 'demo-mcp',
            'metadata' => array_merge(['endpoint' => 'https://mcp.test/mcp', 'protocol' => 'mcp'], $meta),
        ]);
    }

    public function test_only_admins_can_sync(): void
    {
        $connector = $this->connector();
        $this->postJson("/api/admin/catalog/{$connector->id}/sync")->assertStatus(401);

        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->postJson("/api/admin/catalog/{$connector->id}/sync")->assertStatus(403);
    }

    public function test_non_connector_cannot_be_synced(): void
    {
        $admin = $this->admin();
        $tool = CatalogItem::create(['type' => 'tool', 'name' => 'X', 'slug' => 'x']);

        $this->actingAs($admin)->postJson("/api/admin/catalog/{$tool->id}/sync")
            ->assertStatus(422);
    }

    public function test_mcp_connector_imports_tools_and_prompts(): void
    {
        Http::fake([
            'mcp.test/*' => Http::sequence()
                ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2025-06-18']], 200)
                ->push('', 202) // initialized notification
                ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [
                    ['name' => 'echo', 'description' => 'Echoes input', 'inputSchema' => ['type' => 'object']],
                    ['name' => 'search', 'description' => 'Search'],
                ]]], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 3, 'result' => ['prompts' => [
                    ['name' => 'greeting', 'description' => 'A greeting'],
                ]]], 200),
        ]);

        $admin = $this->admin();
        $connector = $this->connector();

        $response = $this->actingAs($admin)->postJson("/api/admin/catalog/{$connector->id}/sync");

        $response->assertStatus(200)
            ->assertJsonPath('counts.tools', 2)
            ->assertJsonPath('counts.prompts', 1);

        $this->assertDatabaseHas('catalog_items', ['type' => 'tool', 'slug' => 'demo-mcp-echo', 'provider' => 'Demo MCP']);
        $this->assertDatabaseHas('catalog_items', ['type' => 'prompt', 'slug' => 'demo-mcp-greeting']);
    }

    public function test_resync_is_idempotent_and_preserves_activation(): void
    {
        $toolsResult = ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [['name' => 'echo']]]];

        Http::fake([
            'mcp.test/*' => Http::sequence()
                ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => []], 200)->push('', 202)
                ->push($toolsResult, 200)->push(['jsonrpc' => '2.0', 'id' => 3, 'result' => ['prompts' => []]], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => []], 200)->push('', 202)
                ->push($toolsResult, 200)->push(['jsonrpc' => '2.0', 'id' => 3, 'result' => ['prompts' => []]], 200),
        ]);

        $admin = $this->admin();
        $connector = $this->connector();

        $this->actingAs($admin)->postJson("/api/admin/catalog/{$connector->id}/sync")->assertStatus(200);

        // Activate the imported tool, then re-sync.
        $tool = CatalogItem::where('slug', 'demo-mcp-echo')->firstOrFail();
        $tool->update(['is_active' => true]);

        $this->actingAs($admin)->postJson("/api/admin/catalog/{$connector->id}/sync")->assertStatus(200);

        // No duplicate, and activation survived.
        $this->assertSame(1, CatalogItem::where('type', 'tool')->where('slug', 'demo-mcp-echo')->count());
        $this->assertTrue($tool->fresh()->is_active);
    }

    public function test_a2a_connector_imports_skills(): void
    {
        Http::fake([
            'agent.test/.well-known/agent-card.json' => Http::response([
                'name' => 'Demo Agent',
                'skills' => [
                    ['id' => 'summarise', 'name' => 'Summarise', 'description' => 'Summarises text'],
                    ['id' => 'translate', 'name' => 'Translate'],
                ],
            ], 200),
        ]);

        $admin = $this->admin();
        $connector = CatalogItem::create([
            'type' => 'connector',
            'name' => 'Demo A2A',
            'slug' => 'demo-a2a',
            'metadata' => ['endpoint' => 'https://agent.test/a2a', 'protocol' => 'a2a'],
        ]);

        $response = $this->actingAs($admin)->postJson("/api/admin/catalog/{$connector->id}/sync");

        $response->assertStatus(200)->assertJsonPath('counts.skills', 2);
        $this->assertDatabaseHas('catalog_items', ['type' => 'skill', 'slug' => 'demo-a2a-summarise', 'provider' => 'Demo A2A']);
    }

    public function test_sync_reports_upstream_failure(): void
    {
        Http::fake(['mcp.test/*' => Http::response(['error' => 'boom'], 500)]);

        $admin = $this->admin();
        $connector = $this->connector();

        $this->actingAs($admin)->postJson("/api/admin/catalog/{$connector->id}/sync")
            ->assertStatus(502);
    }

    public function test_sync_rejects_a_non_public_endpoint(): void
    {
        $admin = $this->admin();
        $connector = $this->connector(['endpoint' => 'http://169.254.169.254/mcp']);

        $this->actingAs($admin)->postJson("/api/admin/catalog/{$connector->id}/sync")
            ->assertStatus(422);
    }

    public function test_creating_a_connector_requires_a_valid_endpoint(): void
    {
        $admin = $this->admin();

        // Missing endpoint.
        $this->actingAs($admin)->postJson('/api/admin/catalog', [
            'type' => 'connector',
            'name' => 'No endpoint',
        ])->assertStatus(422)->assertJsonValidationErrors(['metadata.endpoint']);

        // SSRF endpoint.
        $this->actingAs($admin)->postJson('/api/admin/catalog', [
            'type' => 'connector',
            'name' => 'Internal',
            'metadata' => ['endpoint' => 'http://localhost/mcp', 'protocol' => 'mcp'],
        ])->assertStatus(422)->assertJsonValidationErrors(['metadata.endpoint']);
    }
}
