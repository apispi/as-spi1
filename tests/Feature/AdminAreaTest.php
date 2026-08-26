<?php

namespace Tests\Feature;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAreaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_an_admin_sees_a_users_full_detail(): void
    {
        $org = Organisation::create(['name' => 'Acme', 'slug' => 'acme']);
        $member = User::factory()->create(['organisation_id' => $org->id, 'scx_api_key' => 'sk-secret']);
        $member->savedRequests()->create([
            'name' => 'Ping', 'protocol' => 'rest', 'method' => 'GET',
            'url' => 'https://api.example.com/ping',
        ]);

        $response = $this->actingAs($this->admin())->getJson("/api/admin/users/{$member->id}")
            ->assertOk()
            ->assertJsonPath('user.email', $member->email)
            ->assertJsonPath('user.organisation.name', 'Acme')
            ->assertJsonPath('counts.saved_requests', 1);

        // Whether a key is set is useful; the key itself is never exposed.
        $response->assertJsonPath('user.has_scx_key', true);
        $this->assertStringNotContainsString('sk-secret', $response->getContent());
    }

    public function test_an_admin_can_move_a_user_between_organisations(): void
    {
        $admin = $this->admin();
        $org = Organisation::create(['name' => 'Acme', 'slug' => 'acme']);
        $member = User::factory()->create();

        $this->actingAs($admin)->putJson("/api/admin/users/{$member->id}/organisation", [
            'organisation_id' => $org->id,
        ])->assertOk();
        $this->assertSame($org->id, $member->fresh()->organisation_id);

        // A null id unassigns rather than erroring.
        $this->actingAs($admin)->putJson("/api/admin/users/{$member->id}/organisation", [
            'organisation_id' => null,
        ])->assertOk();
        $this->assertNull($member->fresh()->organisation_id);

        $this->assertDatabaseHas('admin_actions', ['action' => 'unassign_organisation']);
    }

    public function test_organisations_can_be_managed_and_slugs_stay_unique(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/organisations', ['name' => 'Acme Ltd'])
            ->assertStatus(201)->assertJsonPath('slug', 'acme-ltd');

        // A different name that slugifies the same must not collide.
        $this->actingAs($admin)->postJson('/api/admin/organisations', ['name' => 'Acme  Ltd!'])
            ->assertStatus(201)->assertJsonPath('slug', 'acme-ltd-2');

        $this->actingAs($admin)->getJson('/api/admin/organisations')
            ->assertOk()->assertJsonCount(2, 'organisations');
    }

    public function test_deleting_an_organisation_unassigns_members_rather_than_deleting_them(): void
    {
        $org = Organisation::create(['name' => 'Acme', 'slug' => 'acme']);
        $member = User::factory()->create(['organisation_id' => $org->id]);

        $this->actingAs($this->admin())->deleteJson("/api/admin/organisations/{$org->id}")->assertOk();

        // The account belongs to a person, not to the grouping.
        $this->assertDatabaseHas('users', ['id' => $member->id]);
        $this->assertNull($member->fresh()->organisation_id);
    }

    public function test_monitoring_lists_every_users_monitors_failing_first(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create();
        $collection = $owner->collections()->create(['name' => 'Smoke']);

        $owner->monitors()->create([
            'collection_id' => $collection->id, 'name' => 'Healthy',
            'interval_minutes' => 60, 'last_status' => 'passing',
        ]);
        $owner->monitors()->create([
            'collection_id' => $collection->id, 'name' => 'Broken',
            'interval_minutes' => 60, 'last_status' => 'failing',
        ]);

        $this->actingAs($admin)->getJson('/api/admin/monitoring')
            ->assertOk()
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.failing', 1)
            // Failing first: an admin opens this page to find what is broken.
            ->assertJsonPath('monitors.0.name', 'Broken')
            ->assertJsonPath('monitors.0.owner.email', $owner->email);
    }

    public function test_the_admin_area_is_closed_to_non_admins(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $target = User::factory()->create();

        foreach ([
            ['get', "/api/admin/users/{$target->id}"],
            ['get', '/api/admin/monitoring'],
            ['get', '/api/admin/organisations'],
        ] as [$method, $url]) {
            $this->actingAs($user)->{$method.'Json'}($url)->assertStatus(403);
        }

        $this->actingAs($user)->postJson('/api/admin/organisations', ['name' => 'Sneaky'])
            ->assertStatus(403);
    }
}
