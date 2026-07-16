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
}
