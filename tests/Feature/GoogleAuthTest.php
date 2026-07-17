<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function fakeGoogleUser(array $overrides = []): void
    {
        $account = (new SocialiteUser)->map(array_merge([
            'id' => 'google-123',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'avatar' => 'https://lh3.googleusercontent.com/a/ada.png',
        ], $overrides));

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($account);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_redirect_route_is_reachable_and_throttled_config_aside(): void
    {
        // With no client_id configured it should bounce back, not error.
        config(['services.google.client_id' => null]);

        $this->get('/auth/google/redirect')->assertRedirect('/login?error=google_unavailable');
    }

    public function test_callback_creates_a_new_user_and_logs_them_in(): void
    {
        $this->fakeGoogleUser();

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'email' => 'ada@example.com',
            'google_id' => 'google-123',
            'name' => 'Ada Lovelace',
        ]);

        // OAuth-created accounts have no password.
        $this->assertNull(User::where('email', 'ada@example.com')->value('password'));
    }

    public function test_callback_links_google_to_an_existing_email_account(): void
    {
        $existing = User::factory()->create([
            'email' => 'ada@example.com',
            'google_id' => null,
        ]);

        $this->fakeGoogleUser();

        $this->get('/auth/google/callback')->assertRedirect('/dashboard');

        $this->assertSame('google-123', $existing->fresh()->google_id);
        // No duplicate account was created.
        $this->assertSame(1, User::where('email', 'ada@example.com')->count());
        $this->assertAuthenticatedAs($existing->fresh());
    }

    public function test_callback_matches_an_existing_google_id_even_if_email_changed(): void
    {
        $existing = User::factory()->create([
            'email' => 'old@example.com',
            'google_id' => 'google-123',
        ]);

        $this->fakeGoogleUser(['email' => 'new@example.com']);

        $this->get('/auth/google/callback')->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($existing->fresh());
        $this->assertSame(1, User::where('google_id', 'google-123')->count());
    }

    public function test_callback_rejects_an_account_with_no_email(): void
    {
        $this->fakeGoogleUser(['email' => null]);

        $this->get('/auth/google/callback')->assertRedirect('/login?error=google_no_email');
        $this->assertGuest();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
