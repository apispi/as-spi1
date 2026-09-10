<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminUserRemediationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function seedSession(User $user, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $user->id, 'ip_address' => '1.2.3.4',
            'user_agent' => 'x', 'payload' => '', 'last_activity' => time(),
        ]);
    }

    public function test_the_detail_payload_reports_security_posture(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $this->seedSession($target, 'sess-1');
        $this->seedSession($target, 'sess-2');

        $this->actingAs($admin)->getJson("/api/admin/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('user.two_factor_enabled', false)
            ->assertJsonPath('user.active_sessions', 2);
    }

    public function test_an_admin_can_sign_a_user_out_of_all_sessions(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $this->seedSession($target, 'sess-1');
        $this->seedSession($target, 'sess-2');

        $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/revoke-sessions")
            ->assertOk()->assertJsonPath('active_sessions', 0);

        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());
        $this->assertDatabaseHas('admin_actions', ['action' => 'revoke_sessions', 'target_user_id' => $target->id]);
    }

    public function test_an_admin_can_reset_two_factor_for_account_recovery(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        // Enable 2FA directly on the model.
        $secret = (new Totp)->generateSecret();
        $target->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
        $this->assertTrue($target->fresh()->hasTwoFactorEnabled());

        $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/disable-2fa")
            ->assertOk()->assertJsonPath('two_factor_enabled', false);

        $this->assertFalse($target->fresh()->hasTwoFactorEnabled());
        $this->assertDatabaseHas('admin_actions', ['action' => 'disable_two_factor', 'target_user_id' => $target->id]);
    }

    public function test_resetting_2fa_on_a_user_without_it_is_rejected(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();

        $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/disable-2fa")->assertStatus(422);
    }

    public function test_remediation_requires_admin(): void
    {
        $target = User::factory()->create();
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->postJson("/api/admin/users/{$target->id}/revoke-sessions")->assertStatus(403);
    }
}
