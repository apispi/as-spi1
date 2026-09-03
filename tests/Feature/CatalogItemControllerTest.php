<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogItemControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    protected function makeItem(array $overrides = []): CatalogItem
    {
        return CatalogItem::create(array_merge([
            'type' => 'agent',
            'name' => 'Research Agent',
            'slug' => 'research-agent',
            'is_active' => false,
        ], $overrides));
    }

    public function test_guests_and_non_admins_are_blocked(): void
    {
        $this->getJson('/api/admin/catalog')->assertStatus(401);

        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->getJson('/api/admin/catalog')->assertStatus(403);
        $this->actingAs($user)->postJson('/api/admin/catalog', [])->assertStatus(403);
    }

    public function test_items_can_be_filtered_by_type(): void
    {
        $admin = $this->admin();
        $this->makeItem(['type' => 'agent', 'slug' => 'a']);
        $this->makeItem(['type' => 'skill', 'slug' => 'b', 'name' => 'A Skill']);

        $response = $this->actingAs($admin)->getJson('/api/admin/catalog?type=skill');

        $response->assertStatus(200)->assertJsonCount(1);
        $this->assertSame('A Skill', $response->json('0.name'));
    }

    public function test_active_filter_returns_only_active_items(): void
    {
        $admin = $this->admin();
        $this->makeItem(['slug' => 'off', 'name' => 'Inactive', 'is_active' => false]);
        $this->makeItem(['slug' => 'on', 'name' => 'Enabled', 'is_active' => true]);

        $response = $this->actingAs($admin)->getJson('/api/admin/catalog?type=agent&active=1');

        $response->assertStatus(200)->assertJsonCount(1);
        $this->assertSame('Enabled', $response->json('0.name'));
    }

    public function test_invalid_type_is_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/api/admin/catalog?type=dragon')
            ->assertStatus(422)->assertJsonValidationErrors(['type']);
    }

    public function test_counts_cover_every_type(): void
    {
        $admin = $this->admin();
        $this->makeItem(['type' => 'agent', 'slug' => 'a1']);
        $this->makeItem(['type' => 'agent', 'slug' => 'a2', 'name' => 'Second']);
        $this->makeItem(['type' => 'tool', 'slug' => 't1', 'name' => 'Tool', 'is_active' => true]);

        $response = $this->actingAs($admin)->getJson('/api/admin/catalog/counts');

        $response->assertStatus(200)
            ->assertJsonPath('agent', 2)
            ->assertJsonPath('tool', 1)
            ->assertJsonPath('prompt', 0);

        // Active-only counts.
        $this->actingAs($admin)->getJson('/api/admin/catalog/counts?active=1')
            ->assertJsonPath('agent', 0)
            ->assertJsonPath('tool', 1);
    }

    public function test_creating_an_item_generates_a_unique_slug(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/catalog', [
            'type' => 'agent',
            'name' => 'Research Agent',
        ])->assertStatus(201)->assertJsonPath('slug', 'research-agent');

        // Same name again must not collide on the (type, slug) unique index.
        $this->actingAs($admin)->postJson('/api/admin/catalog', [
            'type' => 'agent',
            'name' => 'Research Agent',
        ])->assertStatus(201)->assertJsonPath('slug', 'research-agent-2');
    }

    public function test_creating_rejects_an_invalid_type(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/catalog', [
            'type' => 'wizard',
            'name' => 'Nope',
        ])->assertStatus(422)->assertJsonValidationErrors(['type']);
    }

    public function test_toggling_moves_an_item_between_catalog_and_active(): void
    {
        $admin = $this->admin();
        $item = $this->makeItem(['is_active' => false]);

        $this->actingAs($admin)->postJson("/api/admin/catalog/{$item->id}/toggle-active")
            ->assertStatus(200)->assertJsonPath('item.is_active', true);
        $this->assertTrue($item->fresh()->is_active);

        $this->actingAs($admin)->postJson("/api/admin/catalog/{$item->id}/toggle-active")
            ->assertStatus(200)->assertJsonPath('item.is_active', false);
        $this->assertFalse($item->fresh()->is_active);
    }

    public function test_items_can_be_updated_and_deleted(): void
    {
        $admin = $this->admin();
        $item = $this->makeItem();

        $this->actingAs($admin)->putJson("/api/admin/catalog/{$item->id}", ['name' => 'Renamed'])
            ->assertStatus(200)->assertJsonPath('name', 'Renamed');

        $this->actingAs($admin)->deleteJson("/api/admin/catalog/{$item->id}")->assertStatus(200);
        $this->assertDatabaseMissing('catalog_items', ['id' => $item->id]);
    }

    /**
     * The whole point of the Catalog admin: every content type can be created,
     * listed, activated (moved to Active), edited, and removed. This exercises
     * that lifecycle for each named type so no type silently loses a function.
     */
    public function test_full_crud_lifecycle_works_for_every_named_type(): void
    {
        $admin = $this->admin();

        foreach (['agent', 'skill', 'tool', 'prompt', 'connector'] as $type) {
            $payload = [
                'type' => $type,
                'name' => ucfirst($type).' One',
                'description' => 'A '.$type,
                'provider' => 'internal',
                'version' => '1.0.0',
            ];
            // Connectors require endpoint wiring.
            if ($type === 'connector') {
                $payload['metadata'] = ['endpoint' => 'https://example.com/mcp', 'protocol' => 'mcp'];
            }

            // Create → appears in Catalog for its type.
            $created = $this->actingAs($admin)->postJson('/api/admin/catalog', $payload)
                ->assertStatus(201)
                ->assertJsonPath('type', $type);
            $id = $created->json('id');

            // Created into the Catalog, inactive by default.
            $this->assertFalse((bool) CatalogItem::find($id)->is_active);

            $this->actingAs($admin)->getJson("/api/admin/catalog?type={$type}")
                ->assertStatus(200)
                ->assertJsonFragment(['id' => $id]);

            // Activate → moves into the Active section for its type.
            $this->actingAs($admin)->postJson("/api/admin/catalog/{$id}/toggle-active")
                ->assertStatus(200)->assertJsonPath('item.is_active', true);
            $this->actingAs($admin)->getJson("/api/admin/catalog?type={$type}&active=1")
                ->assertStatus(200)->assertJsonFragment(['id' => $id]);

            // Edit.
            $this->actingAs($admin)->putJson("/api/admin/catalog/{$id}", ['name' => ucfirst($type).' Renamed'])
                ->assertStatus(200)->assertJsonPath('name', ucfirst($type).' Renamed');

            // Deactivate → leaves the Active section.
            $this->actingAs($admin)->postJson("/api/admin/catalog/{$id}/toggle-active")
                ->assertStatus(200)->assertJsonPath('item.is_active', false);
            $this->actingAs($admin)->getJson("/api/admin/catalog?type={$type}&active=1")
                ->assertStatus(200)->assertJsonMissing(['id' => $id]);

            // Delete.
            $this->actingAs($admin)->deleteJson("/api/admin/catalog/{$id}")->assertStatus(200);
            $this->assertDatabaseMissing('catalog_items', ['id' => $id]);
        }
    }

    public function test_search_filters_the_list_by_name_and_metadata(): void
    {
        $admin = $this->admin();
        $this->makeItem(['type' => 'connector', 'slug' => 'billing', 'name' => 'Billing Connector', 'metadata' => ['endpoint' => 'https://pay.example.com/mcp', 'protocol' => 'mcp']]);
        $this->makeItem(['type' => 'connector', 'slug' => 'weather', 'name' => 'Weather Connector', 'metadata' => ['endpoint' => 'https://wx.example.com/mcp', 'protocol' => 'mcp']]);

        // Match by name.
        $this->actingAs($admin)->getJson('/api/admin/catalog?type=connector&q=Billing')
            ->assertStatus(200)->assertJsonCount(1)->assertJsonFragment(['slug' => 'billing']);

        // Match by metadata (endpoint host lives in the JSON blob).
        $this->actingAs($admin)->getJson('/api/admin/catalog?type=connector&q=wx.example.com')
            ->assertStatus(200)->assertJsonCount(1)->assertJsonFragment(['slug' => 'weather']);
    }

    public function test_search_spans_types_via_the_counts(): void
    {
        $admin = $this->admin();
        $this->makeItem(['type' => 'agent', 'slug' => 'atlas-agent', 'name' => 'Atlas Agent']);
        $this->makeItem(['type' => 'skill', 'slug' => 'atlas-skill', 'name' => 'Atlas Skill']);
        $this->makeItem(['type' => 'tool', 'slug' => 'other', 'name' => 'Unrelated']);

        // Counts filtered by the term reflect matches across every type, so the
        // tab badges guide the admin to where matches live.
        $this->actingAs($admin)->getJson('/api/admin/catalog/counts?q=Atlas')
            ->assertStatus(200)
            ->assertJsonPath('agent', 1)
            ->assertJsonPath('skill', 1)
            ->assertJsonPath('tool', 0);
    }

    public function test_blank_search_returns_everything(): void
    {
        $admin = $this->admin();
        $this->makeItem(['type' => 'agent', 'slug' => 'x1', 'name' => 'One']);
        $this->makeItem(['type' => 'agent', 'slug' => 'x2', 'name' => 'Two']);

        $this->actingAs($admin)->getJson('/api/admin/catalog?type=agent&q=')
            ->assertStatus(200)->assertJsonCount(2);
    }
}
