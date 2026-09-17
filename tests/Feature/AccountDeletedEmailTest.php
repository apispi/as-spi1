<?php

namespace Tests\Feature;

use App\Mail\AccountDeletedMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccountDeletedEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_deletion_sends_a_confirmation_and_still_erases(): void
    {
        Mail::fake();
        $user = User::factory()->create(['is_admin' => false, 'name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

        $this->actingAs($user)->deleteJson('/api/user/account')->assertOk();

        Mail::assertSent(AccountDeletedMail::class, fn ($m) => $m->hasTo('ada@example.com'));
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_a_blocked_admin_deletion_sends_nothing(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->deleteJson('/api/user/account')->assertStatus(422);

        Mail::assertNothingSent();
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_the_confirmation_states_permanent_deletion(): void
    {
        $mail = new AccountDeletedMail('Ada');

        $this->assertSame('Your Spi account has been deleted', $mail->envelope()->subject);
        $mail->assertSeeInHtml('permanently');
        $mail->assertSeeInHtml('Create a new account');
    }
}
