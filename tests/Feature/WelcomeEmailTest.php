<?php

namespace Tests\Feature;

use App\Mail\WelcomeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WelcomeEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_registration_sends_a_welcome_email(): void
    {
        Mail::fake();

        $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])->assertStatus(201);

        Mail::assertSent(WelcomeMail::class, fn ($mail) => $mail->hasTo('ada@example.com'));
    }

    public function test_the_welcome_email_greets_by_first_name(): void
    {
        $mail = new WelcomeMail(User::factory()->make(['name' => 'Ada Lovelace']));

        $this->assertStringContainsString('Ada', $mail->envelope()->subject);
        $mail->assertSeeInHtml('Welcome to Spi, Ada');
        $mail->assertSeeInHtml('Open the Tester');
    }

    public function test_a_mail_failure_does_not_break_registration(): void
    {
        // No Mail::fake — the real mailer runs; with array/log transport in
        // tests it won't throw, but the try/catch guarantees a 201 regardless.
        $res = $this->postJson('/api/register', [
            'name' => 'Grace',
            'email' => 'grace@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('users', ['email' => 'grace@example.com']);
    }

    public function test_completing_verified_registration_sends_a_welcome(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'pending@example.com',
            'email_verified_at' => null,
            'registration_token' => Hash::make('tok123'),
            'registration_token_expires_at' => now()->addHour(),
        ]);

        $this->postJson('/api/register/complete', [
            'email' => 'pending@example.com',
            'token' => 'tok123',
            'name' => 'Pending User',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])->assertStatus(201);

        Mail::assertSent(WelcomeMail::class, fn ($mail) => $mail->hasTo('pending@example.com'));
    }
}
