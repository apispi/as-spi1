<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_every_catalog_type(): void
    {
        $this->seed(CatalogSeeder::class);

        foreach (CatalogItem::TYPES as $type) {
            $this->assertGreaterThan(0, CatalogItem::ofType($type)->count(), "No {$type} items were seeded.");
        }
    }

    public function test_it_registers_an_scx_connector(): void
    {
        $this->seed(CatalogSeeder::class);

        $scx = CatalogItem::where(['type' => 'connector', 'slug' => 'scx-ai'])->first();

        $this->assertNotNull($scx);
        $this->assertSame('SCX AI', $scx->name);
        $this->assertSame('https://api.scx.ai/v1/chat/completions', $scx->metadata['endpoint']);

        // SCX is authenticated per user with their own stored key, so the
        // connector must not carry a shared workspace credential.
        $this->assertArrayNotHasKey('auth_header', $scx->metadata);
    }

    public function test_connector_items_carry_their_connectors_endpoint_but_never_its_auth(): void
    {
        $this->seed(CatalogSeeder::class);

        $tool = CatalogItem::where(['type' => 'tool', 'slug' => 'spi-gateway-http-request'])->firstOrFail();

        $this->assertSame('spi-gateway', $tool->metadata['connector_slug']);
        $this->assertSame('https://apispi.com/api/gateway/tools', $tool->metadata['endpoint']);
        $this->assertSame('mcp', $tool->metadata['protocol']);
        $this->assertArrayNotHasKey('auth_header', $tool->metadata);
        $this->assertSame('Spi Gateway', $tool->provider);
    }

    public function test_tools_prompts_and_resources_expose_what_the_tester_reads(): void
    {
        $this->seed(CatalogSeeder::class);

        // ToolController@active reads metadata.inputSchema as input_schema,
        // which RequestPanel turns into a tools/call template.
        $tool = CatalogItem::ofType('tool')->active()->first();
        $this->assertArrayHasKey('inputSchema', $tool->metadata);
        $this->assertSame('object', $tool->metadata['inputSchema']['type']);

        $prompt = CatalogItem::ofType('prompt')->active()->first();
        $this->assertArrayHasKey('arguments', $prompt->metadata);
        $this->assertArrayHasKey('name', $prompt->metadata['arguments'][0]);

        $resource = CatalogItem::ofType('resource')->active()->first();
        $this->assertArrayHasKey('uri', $resource->metadata);
        $this->assertArrayHasKey('mimeType', $resource->metadata);
    }

    public function test_reseeding_creates_no_duplicates_and_preserves_activation(): void
    {
        $this->seed(CatalogSeeder::class);
        $count = CatalogItem::count();

        // An admin deactivates something, then a later deploy re-seeds.
        $item = CatalogItem::ofType('tool')->active()->firstOrFail();
        $item->update(['is_active' => false]);

        $this->seed(CatalogSeeder::class);

        $this->assertSame($count, CatalogItem::count());
        $this->assertFalse($item->fresh()->is_active, 'Re-seeding clobbered an admin activation choice.');
    }

    public function test_seeded_slugs_match_what_a_real_sync_would_produce(): void
    {
        // ConnectorSyncController::import slugs items as
        // "{connectorSlug}-{Str::slug(name)}". Matching it means syncing the
        // connector for real updates these rows instead of duplicating them.
        $this->seed(CatalogSeeder::class);

        $connector = CatalogItem::where(['type' => 'connector', 'slug' => 'spi-gateway'])->firstOrFail();

        foreach (CatalogItem::ofType('tool')->get() as $tool) {
            if (($tool->metadata['connector_slug'] ?? null) !== $connector->slug) {
                continue;
            }

            $this->assertSame(
                $connector->slug.'-'.\Illuminate\Support\Str::slug($tool->name),
                $tool->slug
            );
        }
    }
}
