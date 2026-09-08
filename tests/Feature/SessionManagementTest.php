<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SessionManagementTest extends TestCase
{
    use RefreshDatabase;

    private function seedSession(User $user, string $id, string $ua = 'Mozilla/5.0'): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '203.0.113.7',
            'user_agent' => $ua,
            'payload' => '',
            'last_activity' => time(),
        ]);
    }

    private function handle(string $id): string
    {
        return substr(hash('sha256', $id), 0, 24);
    }

    public function test_it_lists_only_the_users_own_sessions_with_parsed_device(): void
    {
        $user = User::factory()->create();
        $this->seedSession($user, 'sess-a', 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537');
        $this->seedSession($user, 'sess-b');
        $this->seedSession(User::factory()->create(), 'other-user-sess');

        $res = $this->actingAs($user)->getJson('/api/user/sessions')->assertOk();

        $this->assertCount(2, $res->json());
        $devices = collect($res->json())->pluck('device')->all();
        $this->assertContains('Chrome on Windows', $devices);
        // The raw session id is never exposed.
        $this->assertStringNotContainsString('sess-a', json_encode($res->json()));
    }

    public function test_revoking_a_session_signs_that_device_out(): void
    {
        $user = User::factory()->create();
        $this->seedSession($user, 'sess-a');

        $this->actingAs($user)->deleteJson('/api/user/sessions/'.$this->handle('sess-a'))
            ->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'sess-a']);
        $this->assertDatabaseHas('audit_events', ['user_id' => $user->id, 'action' => 'session.revoked']);
    }

    public function test_a_foreign_or_unknown_handle_is_not_found(): void
    {
        $user = User::factory()->create();
        $this->seedSession(User::factory()->create(), 'someone-elses');

        // Handle belongs to another user's session → not revocable here.
        $this->actingAs($user)->deleteJson('/api/user/sessions/'.$this->handle('someone-elses'))
            ->assertStatus(404);
        $this->assertDatabaseHas('sessions', ['id' => 'someone-elses']);
    }

    public function test_revoke_others_clears_every_other_session(): void
    {
        $user = User::factory()->create();
        $this->seedSession($user, 'sess-a');
        $this->seedSession($user, 'sess-b');
        $this->seedSession(User::factory()->create(), 'other-user');

        $this->actingAs($user)->deleteJson('/api/user/sessions/others')
            ->assertOk()->assertJsonPath('count', 2);

        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        // Another user's session is untouched.
        $this->assertDatabaseHas('sessions', ['id' => 'other-user']);
        $this->assertDatabaseHas('audit_events', ['user_id' => $user->id, 'action' => 'session.revoked_others']);
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->getJson('/api/user/sessions')->assertStatus(401);
    }
}
