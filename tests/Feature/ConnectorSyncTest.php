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

    public function test_imported_tools_carry_the_endpoint_but_not_the_auth_header(): void
    {
        Http::fake([
            'mcp.test/*' => Http::sequence()
                ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => []], 200)->push('', 202)
                ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [['name' => 'echo']]]], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 3, 'result' => ['prompts' => []]], 200),
        ]);

        $admin = $this->admin();
        $connector = $this->connector(['auth_header' => 'Bearer top-secret']);

        $this->actingAs($admin)->postJson("/api/admin/catalog/{$connector->id}/sync")->assertStatus(200);

        $tool = CatalogItem::where('slug', 'demo-mcp-echo')->firstOrFail();
        $this->assertSame('https://mcp.test/mcp', $tool->metadata['endpoint']);
        $this->assertSame('demo-mcp', $tool->metadata['connector_slug']);
        // The connector's credential must never propagate to imported items.
        $this->assertArrayNotHasKey('auth_header', $tool->metadata);
        $this->assertStringNotContainsString('top-secret', json_encode($tool->metadata));
    }

    public function test_sync_with_activate_flag_activates_imported_items(): void
    {
        Http::fake([
            'mcp.test/*' => Http::sequence()
                ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => []], 200)->push('', 202)
                ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [
                    ['name' => 'echo'], ['name' => 'search'],
                ]]], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 3, 'result' => ['prompts' => [['name' => 'greet']]]], 200),
        ]);

        $admin = $this->admin();
        $connector = $this->connector();

        $response = $this->actingAs($admin)->postJson("/api/admin/catalog/{$connector->id}/sync", ['activate' => true]);

        $response->assertStatus(200)->assertJsonPath('activated', 3);

        // Every imported item is now active.
        $this->assertSame(0, CatalogItem::whereIn('type', ['tool', 'prompt'])->where('is_active', false)->count());
        $this->assertSame(3, CatalogItem::whereIn('type', ['tool', 'prompt'])->where('is_active', true)->count());
    }

    public function test_sync_without_activate_leaves_items_inactive(): void
    {
        Http::fake([
            'mcp.test/*' => Http::sequence()
                ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => []], 200)->push('', 202)
                ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [['name' => 'echo']]]], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 3, 'result' => ['prompts' => []]], 200),
        ]);

        $admin = $this->admin();
        $connector = $this->connector();

        $this->actingAs($admin)->postJson("/api/admin/catalog/{$connector->id}/sync")
            ->assertStatus(200)->assertJsonPath('activated', 0);

        $this->assertFalse(CatalogItem::where('slug', 'demo-mcp-echo')->value('is_active'));
    }

    public function test_check_reports_reachable_for_a_responding_mcp_server(): void
    {
        Http::fake([
            'mcp.test/*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => [
                'protocolVersion' => '2025-06-18',
                'serverInfo' => ['name' => 'demo-mcp', 'version' => '1.0'],
            ]], 200),
        ]);

        $admin = $this->admin();
        $connector = $this->connector();

        $response = $this->actingAs($admin)->postJson("/api/admin/catalog/{$connector->id}/check");

        $response->assertStatus(200)
            ->assertJsonPath('reachable', true)
            ->assertJsonPath('info', 'demo-mcp 1.0');

        // The check result is persisted on the connector.
        $this->assertTrue($connector->fresh()->metadata['last_check_ok']);
    }

    public function test_check_reports_unreachable_without_erroring(): void
    {
        Http::fake(['mcp.test/*' => Http::response('down', 503)]);

        $admin = $this->admin();
        $connector = $this->connector();

        // Unreachable is a 200 answer (reachable=false), not a server error.
        $this->actingAs($admin)->postJson("/api/admin/catalog/{$connector->id}/check")
            ->assertStatus(200)
            ->assertJsonPath('reachable', false);

        $this->assertFalse($connector->fresh()->metadata['last_check_ok']);
    }

    public function test_check_rejects_a_non_connector_and_ssrf_endpoint(): void
    {
        $admin = $this->admin();

        $tool = CatalogItem::create(['type' => 'tool', 'name' => 'X', 'slug' => 'x']);
        $this->actingAs($admin)->postJson("/api/admin/catalog/{$tool->id}/check")->assertStatus(422);

        $internal = $this->connector(['endpoint' => 'http://127.0.0.1/mcp']);
        $this->actingAs($admin)->postJson("/api/admin/catalog/{$internal->id}/check")->assertStatus(422);
    }

    public function test_connector_endpoint_can_be_edited_and_is_ssrf_validated(): void
    {
        $admin = $this->admin();
        $connector = $this->connector();
        // Seed a sync timestamp that editing must preserve.
        $connector->update(['metadata' => array_merge($connector->metadata, ['last_synced_at' => '2026-01-01T00:00:00+00:00'])]);

        // Valid edit.
        $this->actingAs($admin)->putJson("/api/admin/catalog/{$connector->id}", [
            'metadata' => ['endpoint' => 'https://new.test/mcp', 'protocol' => 'mcp', 'auth_header' => 'Bearer k'],
        ])->assertStatus(200);

        $meta = $connector->fresh()->metadata;
        $this->assertSame('https://new.test/mcp', $meta['endpoint']);
        $this->assertSame('Bearer k', $meta['auth_header']);
        // Existing sync timestamp preserved through the merge.
        $this->assertSame('2026-01-01T00:00:00+00:00', $meta['last_synced_at']);

        // SSRF endpoint rejected on edit.
        $this->actingAs($admin)->putJson("/api/admin/catalog/{$connector->id}", [
            'metadata' => ['endpoint' => 'http://localhost/mcp', 'protocol' => 'mcp'],
        ])->assertStatus(422)->assertJsonValidationErrors(['metadata.endpoint']);
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
