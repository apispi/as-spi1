<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivePromptsTest extends TestCase
{
    use RefreshDatabase;

    protected function prompt(array $overrides = [], array $meta = []): CatalogItem
    {
        return CatalogItem::create(array_merge([
            'type' => 'prompt',
            'name' => 'Greeting',
            'slug' => 'demo-greeting',
            'provider' => 'Demo MCP',
            'is_active' => true,
            'metadata' => array_merge([
                'endpoint' => 'https://mcp.test/mcp',
                'protocol' => 'mcp',
                'arguments' => [['name' => 'tone', 'required' => false]],
            ], $meta),
        ], $overrides));
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/prompts/active')->assertStatus(401);
    }

    public function test_returns_active_prompts_with_endpoint_and_arguments(): void
    {
        $this->prompt();
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->getJson('/api/prompts/active')
            ->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Greeting')
            ->assertJsonPath('0.endpoint', 'https://mcp.test/mcp')
            ->assertJsonPath('0.arguments.0.name', 'tone');
    }

    public function test_excludes_inactive_and_endpointless_and_other_types(): void
    {
        $this->prompt(['slug' => 'active-one']);
        $this->prompt(['slug' => 'inactive', 'is_active' => false]);
        $this->prompt(['slug' => 'no-endpoint'], ['endpoint' => null]);
        CatalogItem::create(['type' => 'tool', 'name' => 'A Tool', 'slug' => 't', 'is_active' => true, 'metadata' => ['endpoint' => 'https://x.test']]);

        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/prompts/active')
            ->assertStatus(200)->assertJsonCount(1);
    }

    public function test_never_exposes_the_connector_auth_header(): void
    {
        $this->prompt([], ['auth_header' => 'Bearer super-secret']);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/prompts/active');
        $this->assertStringNotContainsString('super-secret', $response->getContent());
        $response->assertJsonMissingPath('0.auth_header');
    }
}
