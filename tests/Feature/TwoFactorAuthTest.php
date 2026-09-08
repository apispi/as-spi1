<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TwoFactorAuthTest extends TestCase
{
    use RefreshDatabase;

    private Totp $totp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->totp = new Totp;
    }

    private function enable(User $user): array
    {
        $this->actingAs($user)->postJson('/api/user/2fa/setup')->assertOk();
        $secret = $user->fresh()->two_factor_secret;
        $res = $this->actingAs($user)->postJson('/api/user/2fa/confirm', ['code' => $this->currentCode($secret)])
            ->assertOk();

        return ['secret' => $secret, 'recovery' => $res->json('recovery_codes')];
    }

    /** Compute the code an authenticator app would show right now. */
    private function currentCode(string $secret): string
    {
        $ref = new \ReflectionMethod(Totp::class, 'codeAt');
        $ref->setAccessible(true);
        $counter = (int) floor(time() / 30);

        return $ref->invoke($this->totp, $secret, $counter);
    }

    public function test_totp_service_verifies_its_own_codes(): void
    {
        $secret = $this->totp->generateSecret();
        $this->assertTrue($this->totp->verify($secret, $this->currentCode($secret)));
        $this->assertFalse($this->totp->verify($secret, '000000') && $this->currentCode($secret) !== '000000');
        $this->assertFalse($this->totp->verify($secret, '12'));
    }

    public function test_setup_then_confirm_enables_two_factor_and_issues_recovery_codes(): void
    {
        $user = User::factory()->create();

        $setup = $this->actingAs($user)->postJson('/api/user/2fa/setup')->assertOk()
            ->assertJsonStructure(['secret', 'otpauth_uri']);
        $secret = $user->fresh()->two_factor_secret;
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());  // not yet

        $res = $this->actingAs($user)->postJson('/api/user/2fa/confirm', ['code' => $this->currentCode($secret)])
            ->assertOk();
        $this->assertCount(8, $res->json('recovery_codes'));
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_confirm_rejects_a_wrong_code(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/user/2fa/setup')->assertOk();

        $this->actingAs($user)->postJson('/api/user/2fa/confirm', ['code' => '000001'])
            ->assertStatus(422);
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_login_without_2fa_is_unchanged(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret123')]);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonMissing(['two_factor_required' => true]);
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_with_2fa_requires_the_code(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret123')]);
        $this->enable($user->fresh());
        $secret = $user->fresh()->two_factor_secret;
        $this->app['auth']->guard()->logout();

        // Password alone does not authenticate.
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'secret123'])
            ->assertOk()->assertJsonPath('two_factor_required', true);
        $this->assertGuest();

        // The correct code completes the login.
        $this->postJson('/api/login/2fa', ['code' => $this->currentCode($secret)])
            ->assertOk();
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_login_2fa_rejects_a_bad_code_and_audits_it(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret123')]);
        $this->enable($user->fresh());
        $this->app['auth']->guard()->logout();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'secret123'])->assertOk();
        $this->postJson('/api/login/2fa', ['code' => '000001'])->assertStatus(422);
        $this->assertGuest();
        $this->assertDatabaseHas('audit_events', ['user_id' => $user->id, 'action' => 'auth.2fa_failed']);
    }

    public function test_a_recovery_code_completes_login_and_is_single_use(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret123')]);
        $data = $this->enable($user->fresh());
        $recovery = $data['recovery'][0];
        $this->app['auth']->guard()->logout();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'secret123'])->assertOk();
        $this->postJson('/api/login/2fa', ['code' => $recovery])->assertOk();
        $this->assertAuthenticatedAs($user->fresh());

        // Reused → rejected.
        $this->app['auth']->guard()->logout();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'secret123'])->assertOk();
        $this->postJson('/api/login/2fa', ['code' => $recovery])->assertStatus(422);
    }

    public function test_disable_requires_the_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret123')]);
        $this->enable($user->fresh());

        $this->actingAs($user->fresh())->deleteJson('/api/user/2fa', ['password' => 'wrong'])->assertStatus(422);
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());

        $this->actingAs($user->fresh())->deleteJson('/api/user/2fa', ['password' => 'secret123'])->assertOk();
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_the_secret_is_never_exposed_by_the_user_endpoint(): void
    {
        $user = User::factory()->create();
        $this->enable($user->fresh());

        $res = $this->actingAs($user->fresh())->getJson('/api/user')->assertOk();
        $this->assertArrayNotHasKey('two_factor_secret', $res->json());
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $res->json());
    }
}
