<?php

namespace Tests\Feature;

use App\Mail\PasswordChangedMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordChangedEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_changing_a_password_emails_a_security_notice(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password' => Hash::make('secret-password-12')]);

        $this->actingAs($user)->putJson('/api/user/password', [
            'current_password' => 'secret-password-12',
            'password' => 'a-new-secret-99',
            'password_confirmation' => 'a-new-secret-99',
        ])->assertOk();

        Mail::assertSent(PasswordChangedMail::class, fn ($m) => $m->hasTo($user->email));
    }

    public function test_a_rejected_change_sends_no_email(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password' => Hash::make('secret-password-12')]);

        $this->actingAs($user)->putJson('/api/user/password', [
            'current_password' => 'wrong',
            'password' => 'a-new-secret-99',
            'password_confirmation' => 'a-new-secret-99',
        ])->assertStatus(422);

        Mail::assertNothingSent();
    }

    public function test_the_notice_flags_an_unrecognised_change(): void
    {
        $mail = new PasswordChangedMail(User::factory()->make(['name' => 'Ada Lovelace']), '203.0.113.9');

        $this->assertSame('Your Spi password was changed', $mail->envelope()->subject);
        $mail->assertSeeInHtml('was changed');
        $mail->assertSeeInHtml('may be compromised');
        $mail->assertSeeInHtml('Review account security');
        $mail->assertSeeInHtml('203.0.113.9');
    }
}
