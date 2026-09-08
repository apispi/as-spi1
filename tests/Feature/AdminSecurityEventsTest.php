<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSecurityEventsTest extends TestCase
{
    use RefreshDatabase;

    private function seedEvents(): User
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        // A normal sign-in.
        AuditEvent::create(['user_id' => $user->id, 'actor_email' => $user->email, 'action' => 'auth.login', 'ip' => '10.0.0.1']);
        // Several failed logins from one IP (brute-force signal).
        foreach (range(1, 3) as $i) {
            AuditEvent::create(['actor_email' => 'ada@example.com', 'action' => 'auth.login_failed', 'ip' => '203.0.113.9']);
        }
        // A key event from another IP.
        AuditEvent::create(['user_id' => $user->id, 'actor_email' => $user->email, 'action' => 'api_key.created', 'ip' => '10.0.0.2', 'metadata' => ['name' => 'CI']]);

        return $user;
    }

    public function test_admin_only(): void
    {
        // A true guest is unauthenticated; a signed-in non-admin is forbidden.
        $this->getJson('/api/admin/security-events')->assertStatus(401);
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->getJson('/api/admin/security-events')->assertStatus(403);
    }

    public function test_it_lists_events_with_a_summary(): void
    {
        $this->seedEvents();
        $admin = User::factory()->create(['is_admin' => true]);

        $res = $this->actingAs($admin)->getJson('/api/admin/security-events')->assertOk();

        $this->assertGreaterThanOrEqual(5, $res->json('events.total'));
        $this->assertSame(3, $res->json('summary.failed_logins_24h'));
        $this->assertSame(1, $res->json('summary.logins_24h'));
        // The offending IP tops the failed-IP list.
        $this->assertSame('203.0.113.9', $res->json('summary.top_failed_ips.0.ip'));
        $this->assertSame(3, $res->json('summary.top_failed_ips.0.attempts'));
    }

    public function test_it_filters_by_action(): void
    {
        $this->seedEvents();
        $admin = User::factory()->create(['is_admin' => true]);

        $res = $this->actingAs($admin)->getJson('/api/admin/security-events?action=auth.login_failed')->assertOk();

        $actions = collect($res->json('events.data'))->pluck('action')->unique()->all();
        $this->assertSame(['auth.login_failed'], array_values($actions));
    }

    public function test_it_filters_by_ip_or_email_query(): void
    {
        $this->seedEvents();
        $admin = User::factory()->create(['is_admin' => true]);

        $res = $this->actingAs($admin)->getJson('/api/admin/security-events?q=203.0.113.9')->assertOk();
        $this->assertSame(3, $res->json('events.total'));
        $this->assertSame('auth.login_failed', $res->json('events.data.0.action'));
    }

    public function test_an_invalid_action_is_rejected(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->getJson('/api/admin/security-events?action=nope')
            ->assertStatus(422)->assertJsonValidationErrors(['action']);
    }
}
