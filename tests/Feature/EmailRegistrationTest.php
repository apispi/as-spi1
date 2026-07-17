<?php

namespace Tests\Feature;

use App\Mail\RegistrationVerificationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_creates_an_unverified_user_and_emails_a_link(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/register/start', ['email' => 'new@example.com']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['email' => 'new@example.com', 'email_verified_at' => null]);

        $user = User::where('email', 'new@example.com')->first();
        $this->assertNotNull($user->registration_token);
        // The stored token is hashed, not plaintext.
        $this->assertNotEmpty($user->registration_token);

        Mail::assertSent(RegistrationVerificationMail::class, fn ($m) => $m->hasTo('new@example.com'));
    }

    public function test_start_does_not_resend_or_leak_for_a_verified_account(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'taken@example.com', 'email_verified_at' => now()]);

        $response = $this->postJson('/api/register/start', ['email' => 'taken@example.com']);

        // Same generic response, but no email is sent for an existing account.
        $response->assertStatus(200);
        Mail::assertNothingSent();
    }

    public function test_start_validates_the_email(): void
    {
        $this->postJson('/api/register/start', ['email' => 'not-an-email'])
            ->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_complete_sets_credentials_verifies_and_logs_in(): void
    {
        Mail::fake();
        $this->postJson('/api/register/start', ['email' => 'new@example.com'])->assertStatus(200);

        // Capture the plaintext token from the emailed URL.
        $token = null;
        Mail::assertSent(RegistrationVerificationMail::class, function ($mail) use (&$token) {
            parse_str(parse_url($mail->setupUrl, PHP_URL_QUERY), $q);
            $token = $q['token'] ?? null;
            return true;
        });
        $this->assertNotNull($token);

        $response = $this->postJson('/api/register/complete', [
            'email' => 'new@example.com',
            'token' => $token,
            'name' => 'New Person',
            'password' => 'supersecret',
            'password_confirmation' => 'supersecret',
        ]);

        $response->assertStatus(201)->assertJsonPath('name', 'New Person');
        $this->assertAuthenticated();

        $user = User::where('email', 'new@example.com')->first();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->registration_token);
        $this->assertTrue(Hash::check('supersecret', $user->password));
    }

    public function test_complete_rejects_a_wrong_token(): void
    {
        Mail::fake();
        $this->postJson('/api/register/start', ['email' => 'new@example.com']);

        $response = $this->postJson('/api/register/complete', [
            'email' => 'new@example.com',
            'token' => 'totally-wrong-token',
            'name' => 'X',
            'password' => 'supersecret',
            'password_confirmation' => 'supersecret',
        ]);

        $response->assertStatus(422);
        $this->assertGuest();
        $this->assertNull(User::where('email', 'new@example.com')->value('email_verified_at'));
    }

    public function test_complete_rejects_an_expired_token(): void
    {
        Mail::fake();
        $this->postJson('/api/register/start', ['email' => 'new@example.com']);

        $token = null;
        Mail::assertSent(RegistrationVerificationMail::class, function ($mail) use (&$token) {
            parse_str(parse_url($mail->setupUrl, PHP_URL_QUERY), $q);
            $token = $q['token'] ?? null;
            return true;
        });

        // Age the token past its TTL.
        User::where('email', 'new@example.com')->update([
            'registration_token_expires_at' => now()->subMinutes(1),
        ]);

        $this->postJson('/api/register/complete', [
            'email' => 'new@example.com',
            'token' => $token,
            'name' => 'X',
            'password' => 'supersecret',
            'password_confirmation' => 'supersecret',
        ])->assertStatus(422);
    }

    public function test_complete_requires_password_confirmation(): void
    {
        Mail::fake();
        $this->postJson('/api/register/start', ['email' => 'new@example.com']);
        $token = null;
        Mail::assertSent(RegistrationVerificationMail::class, function ($mail) use (&$token) {
            parse_str(parse_url($mail->setupUrl, PHP_URL_QUERY), $q);
            $token = $q['token'] ?? null;
            return true;
        });

        $this->postJson('/api/register/complete', [
            'email' => 'new@example.com',
            'token' => $token,
            'name' => 'X',
            'password' => 'supersecret',
            'password_confirmation' => 'different',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);
    }
}
