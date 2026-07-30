<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class McpConformanceControllerTest extends TestCase
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

    public function test_requires_admin(): void
    {
        $connector = $this->connector();
        $this->postJson("/api/admin/catalog/{$connector->id}/conformance")->assertStatus(401);

        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->postJson("/api/admin/catalog/{$connector->id}/conformance")->assertStatus(403);
    }

    public function test_grades_a_well_behaved_server_highly(): void
    {
        Http::fake([
            'mcp.test/*' => Http::sequence()
                // initialize
                ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => [
                    'protocolVersion' => '2025-06-18',
                    'serverInfo' => ['name' => 'demo', 'version' => '1.0'],
                    'capabilities' => new \stdClass(),
                ]], 200)
                ->push('', 202) // initialized notification
                // tools/list
                ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [
                    ['name' => 'echo', 'description' => 'Echo', 'inputSchema' => ['type' => 'object']],
                    ['name' => 'add', 'description' => 'Add', 'inputSchema' => ['type' => 'object']],
                ]]], 200)
                // unknown method -> -32601
                ->push(['jsonrpc' => '2.0', 'id' => 3, 'error' => ['code' => -32601, 'message' => 'Method not found']], 200)
                // unknown tool -> -32602
                ->push(['jsonrpc' => '2.0', 'id' => 4, 'error' => ['code' => -32602, 'message' => 'Unknown tool']], 200),
        ]);

        $admin = $this->admin();
        $connector = $this->connector();

        $response = $this->actingAs($admin)->postJson("/api/admin/catalog/{$connector->id}/conformance");

        $response->assertStatus(200)
            ->assertJsonPath('score', 100)
            ->assertJsonPath('grade', 'A+');

        $this->assertGreaterThan(5, count($response->json('checks')));

        // The grade is persisted onto the connector for the Catalog to show.
        $connector->refresh();
        $this->assertSame('A+', $connector->metadata['conformance_grade']);
    }

    public function test_failed_handshake_grades_f(): void
    {
        Http::fake([
            'mcp.test/*' => Http::response('nope', 500),
        ]);

        $admin = $this->admin();
        $connector = $this->connector();

        $response = $this->actingAs($admin)->postJson("/api/admin/catalog/{$connector->id}/conformance");

        $response->assertStatus(200)->assertJsonPath('grade', 'F');
    }

    public function test_non_connector_is_rejected(): void
    {
        $admin = $this->admin();
        $tool = CatalogItem::create(['type' => 'tool', 'name' => 'X', 'slug' => 'x']);

        $this->actingAs($admin)->postJson("/api/admin/catalog/{$tool->id}/conformance")
            ->assertStatus(422);
    }
}
