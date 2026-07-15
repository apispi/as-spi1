<?php

namespace Tests\Feature;

use App\Models\RequestHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_name_can_be_updated(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);

        $response = $this->actingAs($user)->putJson('/api/user/profile', ['name' => 'New Name']);

        $response->assertStatus(200)->assertJsonPath('name', 'New Name');
        $this->assertSame('New Name', $user->fresh()->name);
    }

    public function test_stats_reflect_history_and_saved_counts(): void
    {
        $user = User::factory()->create();

        RequestHistory::record($user->id, ['protocol' => 'rest', 'method' => 'GET', 'url' => 'https://a.test', 'status' => 200, 'time_ms' => 5]);
        RequestHistory::record($user->id, ['protocol' => 'mcp', 'method' => 'tools/list', 'url' => 'https://b.test', 'status' => 200, 'time_ms' => 5]);

        $response = $this->actingAs($user)->getJson('/api/user/stats');

        $response->assertStatus(200)
            ->assertJsonPath('requests', 2)
            ->assertJsonPath('saved', 0)
            ->assertJsonPath('active_days', 1);
    }

    public function test_activity_returns_recent_requests_newest_first(): void
    {
        $user = User::factory()->create();

        RequestHistory::record($user->id, ['protocol' => 'rest', 'method' => 'GET', 'url' => 'https://first.test', 'status' => 200, 'time_ms' => 5]);
        RequestHistory::record($user->id, ['protocol' => 'a2a', 'method' => 'message/send', 'url' => 'https://second.test', 'status' => 200, 'time_ms' => 5]);

        $response = $this->actingAs($user)->getJson('/api/user/activity');

        $response->assertStatus(200)->assertJsonCount(2);
        $this->assertSame('request.a2a', $response->json('0.action'));
        $this->assertStringContainsString('second.test', $response->json('0.description'));
    }

    public function test_account_can_be_deleted(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->deleteJson('/api/user/account');

        $response->assertStatus(200);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_admins_cannot_self_delete(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->deleteJson('/api/user/account');

        $response->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_user_endpoints_require_auth(): void
    {
        $this->putJson('/api/user/profile', ['name' => 'x'])->assertStatus(401);
        $this->getJson('/api/user/stats')->assertStatus(401);
        $this->getJson('/api/user/activity')->assertStatus(401);
        $this->deleteJson('/api/user/account')->assertStatus(401);
    }
}
