<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActiveResourcesTest extends TestCase
{
    use RefreshDatabase;

    protected function resource(array $overrides = [], array $meta = []): CatalogItem
    {
        return CatalogItem::create(array_merge([
            'type' => 'resource',
            'name' => 'Readme',
            'slug' => 'demo-readme',
            'provider' => 'Demo MCP',
            'is_active' => true,
            'metadata' => array_merge([
                'endpoint' => 'https://mcp.test/mcp',
                'protocol' => 'mcp',
                'uri' => 'file:///readme.md',
                'mimeType' => 'text/markdown',
            ], $meta),
        ], $overrides));
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/resources/active')->assertStatus(401);
    }

    public function test_returns_active_resources_with_uri_and_endpoint(): void
    {
        $this->resource();
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->getJson('/api/resources/active')
            ->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Readme')
            ->assertJsonPath('0.uri', 'file:///readme.md')
            ->assertJsonPath('0.mime_type', 'text/markdown');
    }

    public function test_excludes_inactive_endpointless_and_uriless(): void
    {
        $this->resource(['slug' => 'ok']);
        $this->resource(['slug' => 'inactive', 'is_active' => false]);
        $this->resource(['slug' => 'no-endpoint'], ['endpoint' => null]);
        $this->resource(['slug' => 'no-uri'], ['uri' => null]);

        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/resources/active')
            ->assertStatus(200)->assertJsonCount(1);
    }

    public function test_never_exposes_the_connector_auth_header(): void
    {
        $this->resource([], ['auth_header' => 'Bearer super-secret']);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/resources/active');
        $this->assertStringNotContainsString('super-secret', $response->getContent());
        $response->assertJsonMissingPath('0.auth_header');
    }
}
