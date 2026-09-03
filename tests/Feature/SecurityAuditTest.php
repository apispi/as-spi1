<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_successful_login_is_audited(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret123')]);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'secret123'])->assertOk();

        $this->assertDatabaseHas('audit_events', ['user_id' => $user->id, 'action' => 'auth.login']);
    }

    public function test_a_failed_login_is_audited_without_a_user(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret123')]);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(401);

        $event = AuditEvent::where('action', 'auth.login_failed')->first();
        $this->assertNotNull($event);
        $this->assertNull($event->user_id);
        $this->assertSame($user->email, $event->actor_email);
    }

    public function test_logout_and_password_change_are_audited(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret123')]);

        $this->actingAs($user)->putJson('/api/user/password', [
            'current_password' => 'secret123',
            'password' => 'newsecret123',
            'password_confirmation' => 'newsecret123',
        ])->assertOk();
        $this->assertDatabaseHas('audit_events', ['user_id' => $user->id, 'action' => 'auth.password_changed']);

        $this->actingAs($user)->postJson('/api/logout')->assertOk();
        $this->assertDatabaseHas('audit_events', ['user_id' => $user->id, 'action' => 'auth.logout']);
    }

    public function test_api_key_creation_and_revocation_are_audited(): void
    {
        $user = User::factory()->create();

        $res = $this->actingAs($user)->postJson('/api/user/api-keys', ['name' => 'CI'])->assertStatus(201);
        $this->assertDatabaseHas('audit_events', ['user_id' => $user->id, 'action' => 'api_key.created']);

        $id = $res->json('id');
        $this->actingAs($user)->deleteJson("/api/user/api-keys/{$id}")->assertOk();
        $this->assertDatabaseHas('audit_events', ['user_id' => $user->id, 'action' => 'api_key.revoked']);
    }

    public function test_account_deletion_is_audited_anonymously(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->deleteJson('/api/user/account')->assertOk();

        $event = AuditEvent::where('action', 'account.deleted')->first();
        $this->assertNotNull($event);
        // Erasure: no identifying detail is retained.
        $this->assertNull($event->user_id);
        $this->assertNull($event->actor_email);
    }

    public function test_a_user_sees_only_their_own_security_log(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        AuditEvent::record('auth.login', $user);
        AuditEvent::record('api_key.created', $user);
        AuditEvent::record('auth.login', $other);

        $res = $this->actingAs($user)->getJson('/api/user/security-log')->assertOk();

        $this->assertCount(2, $res->json());
        $actions = collect($res->json())->pluck('action')->all();
        $this->assertContains('auth.login', $actions);
        $this->assertContains('api_key.created', $actions);
        // The label is humanised for the UI.
        $this->assertContains('Signed in', collect($res->json())->pluck('label')->all());
    }

    public function test_security_log_requires_authentication(): void
    {
        $this->getJson('/api/user/security-log')->assertStatus(401);
    }

    public function test_admin_user_detail_includes_security_events(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $target = User::factory()->create();
        AuditEvent::record('auth.login', $target);

        $this->actingAs($admin)->getJson("/api/admin/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('security_events.0.action', 'auth.login')
            ->assertJsonPath('security_events.0.label', 'Signed in');
    }
}
