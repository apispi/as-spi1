<?php

namespace Tests\Feature;

use App\Models\AdminAction;
use App\Models\SavedRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_guests_are_blocked(): void
    {
        $this->getJson('/api/admin/users')->assertStatus(401);
        $this->getJson('/api/admin/stats')->assertStatus(401);
        $this->getJson('/api/admin/actions')->assertStatus(401);
    }

    public function test_non_admins_are_blocked(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->getJson('/api/admin/users')->assertStatus(403);
        $this->actingAs($user)->getJson('/api/admin/stats')->assertStatus(403);
        $this->actingAs($user)->getJson('/api/admin/actions')->assertStatus(403);
        $this->actingAs($user)->postJson('/api/admin/users/1/toggle-admin')->assertStatus(403);
        $this->actingAs($user)->deleteJson('/api/admin/users/1')->assertStatus(403);
    }

    public function test_users_endpoint_is_paginated(): void
    {
        $admin = $this->admin();
        User::factory()->count(30)->create();

        $response = $this->actingAs($admin)->getJson('/api/admin/users');

        $response->assertStatus(200)
            ->assertJsonPath('per_page', 25)
            ->assertJsonPath('total', 31)
            ->assertJsonCount(25, 'data');
    }

    public function test_users_endpoint_supports_search(): void
    {
        $admin = $this->admin();
        User::factory()->create(['name' => 'Findable Person', 'email' => 'findme@example.com']);
        User::factory()->count(5)->create();

        $response = $this->actingAs($admin)->getJson('/api/admin/users?search=findme');

        $response->assertStatus(200)->assertJsonPath('total', 1);
        $this->assertSame('findme@example.com', $response->json('data.0.email'));
    }

    public function test_admin_cannot_demote_themselves(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson("/api/admin/users/{$admin->id}/toggle-admin");

        $response->assertStatus(422);
        $this->assertTrue($admin->fresh()->is_admin);
    }

    public function test_admin_cannot_delete_themselves(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->deleteJson("/api/admin/users/{$admin->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_toggling_admin_writes_an_audit_entry(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['is_admin' => false]);

        $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/toggle-admin")->assertStatus(200);

        $this->assertTrue($target->fresh()->is_admin);
        $this->assertDatabaseHas('admin_actions', [
            'admin_id' => $admin->id,
            'action' => 'promote_admin',
            'target_user_id' => $target->id,
            'target_email' => $target->email,
        ]);
    }

    public function test_deleting_a_user_writes_an_audit_entry_with_details(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        SavedRequest::create([
            'user_id' => $target->id,
            'name' => 'Their request',
            'protocol' => 'rest',
            'method' => 'GET',
            'url' => 'https://api.example.com',
        ]);

        $this->actingAs($admin)->deleteJson("/api/admin/users/{$target->id}")->assertStatus(200);

        $this->assertDatabaseMissing('users', ['id' => $target->id]);

        $entry = AdminAction::where('action', 'delete_user')->first();
        $this->assertNotNull($entry);
        $this->assertSame($target->email, $entry->target_email);
        $this->assertSame(1, $entry->details['saved_requests_deleted']);
    }

    public function test_actions_endpoint_returns_the_audit_log(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();

        $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/toggle-admin");

        $response = $this->actingAs($admin)->getJson('/api/admin/actions');

        $response->assertStatus(200)->assertJsonPath('total', 1);
        $this->assertSame('promote_admin', $response->json('data.0.action'));
        $this->assertSame($admin->email, $response->json('data.0.admin.email'));
    }

    public function test_stats_includes_new_users_this_week(): void
    {
        $admin = $this->admin();
        User::factory()->create(['created_at' => now()->subDays(2)]);
        User::factory()->create(['created_at' => now()->subMonth()]);

        $response = $this->actingAs($admin)->getJson('/api/admin/stats');

        $response->assertStatus(200)
            ->assertJsonPath('total_users', 3)
            ->assertJsonPath('new_users_this_week', 2);
    }

    public function test_stats_includes_request_volume_and_protocol_breakdown(): void
    {
        $admin = $this->admin();

        \App\Models\RequestHistory::record($admin->id, ['protocol' => 'rest', 'method' => 'GET', 'url' => 'https://a.test', 'status' => 200, 'time_ms' => 5]);
        \App\Models\RequestHistory::record($admin->id, ['protocol' => 'mcp', 'method' => 'tools/list', 'url' => 'https://b.test', 'status' => 200, 'time_ms' => 5]);
        \App\Models\RequestHistory::record($admin->id, ['protocol' => 'mcp', 'method' => 'ping', 'url' => 'https://c.test', 'status' => 200, 'time_ms' => 5]);

        $response = $this->actingAs($admin)->getJson('/api/admin/stats');

        $response->assertStatus(200)
            ->assertJsonPath('total_requests', 3)
            ->assertJsonPath('requests_this_week', 3)
            ->assertJsonPath('protocol_breakdown.rest', 1)
            ->assertJsonPath('protocol_breakdown.mcp', 2)
            ->assertJsonPath('protocol_breakdown.a2a', 0);
    }
}
