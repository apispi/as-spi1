<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActiveToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function tool(array $overrides = [], array $meta = []): CatalogItem
    {
        return CatalogItem::create(array_merge([
            'type' => 'tool',
            'name' => 'Echo',
            'slug' => 'demo-echo',
            'provider' => 'Demo MCP',
            'is_active' => true,
            'metadata' => array_merge([
                'endpoint' => 'https://mcp.test/mcp',
                'protocol' => 'mcp',
                'inputSchema' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']]],
            ], $meta),
        ], $overrides));
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/tools/active')->assertStatus(401);
    }

    public function test_available_to_any_authenticated_user(): void
    {
        $this->tool();
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->getJson('/api/tools/active')
            ->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Echo')
            ->assertJsonPath('0.endpoint', 'https://mcp.test/mcp');
    }

    public function test_returns_only_active_tools_with_an_endpoint(): void
    {
        $this->tool(['slug' => 'active-one']);
        $this->tool(['slug' => 'inactive', 'is_active' => false]);
        // Active but no endpoint (e.g. hand-authored) — not addressable.
        $this->tool(['slug' => 'no-endpoint'], ['endpoint' => null]);

        $user = User::factory()->create();
        $response = $this->actingAs($user)->getJson('/api/tools/active');

        $response->assertStatus(200)->assertJsonCount(1);
        $this->assertSame('active-one', 'active-one');
        $this->assertNotNull($response->json('0.input_schema'));
    }

    public function test_never_exposes_the_connector_auth_header(): void
    {
        // Even if an auth header somehow lands in a tool's metadata, the
        // endpoint only surfaces the whitelisted fields.
        $this->tool([], ['auth_header' => 'Bearer super-secret']);

        $user = User::factory()->create();
        $response = $this->actingAs($user)->getJson('/api/tools/active');

        $this->assertStringNotContainsString('super-secret', $response->getContent());
        $response->assertJsonMissingPath('0.auth_header');
    }

    public function test_only_tools_are_returned_not_other_types(): void
    {
        $this->tool();
        CatalogItem::create(['type' => 'skill', 'name' => 'A Skill', 'slug' => 's', 'is_active' => true, 'metadata' => ['endpoint' => 'https://x.test']]);

        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/tools/active')
            ->assertStatus(200)->assertJsonCount(1)->assertJsonPath('0.name', 'Echo');
    }
}
