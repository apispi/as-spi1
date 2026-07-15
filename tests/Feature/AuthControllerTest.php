<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_and_logs_in_a_user(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'New Person',
            'email' => 'new@example.com',
            'password' => 'supersecret',
            'password_confirmation' => 'supersecret',
        ]);

        $response->assertStatus(201);
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
    }

    public function test_user_endpoint_never_exposes_the_scx_api_key(): void
    {
        $user = User::factory()->create(['scx_api_key' => 'sk-secret-value']);

        $response = $this->actingAs($user)->getJson('/api/user');

        $response->assertStatus(200);
        $response->assertJsonMissingPath('scx_api_key');
        $this->assertStringNotContainsString('sk-secret-value', $response->getContent());
    }

    public function test_user_endpoint_never_exposes_the_password_hash(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/user');

        $response->assertJsonMissingPath('password');
    }

    public function test_password_can_be_changed_with_the_correct_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('oldpassword')]);

        $response = $this->actingAs($user)->putJson('/api/user/password', [
            'current_password' => 'oldpassword',
            'password' => 'brandnewpassword',
            'password_confirmation' => 'brandnewpassword',
        ]);

        $response->assertStatus(200);
        $this->assertTrue(Hash::check('brandnewpassword', $user->fresh()->password));
    }

    public function test_password_change_rejects_a_wrong_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('oldpassword')]);

        $response = $this->actingAs($user)->putJson('/api/user/password', [
            'current_password' => 'notmypassword',
            'password' => 'brandnewpassword',
            'password_confirmation' => 'brandnewpassword',
        ]);

        $response->assertStatus(422);
        $this->assertTrue(Hash::check('oldpassword', $user->fresh()->password));
    }

    public function test_password_change_requires_confirmation(): void
    {
        $user = User::factory()->create(['password' => Hash::make('oldpassword')]);

        $response = $this->actingAs($user)->putJson('/api/user/password', [
            'current_password' => 'oldpassword',
            'password' => 'brandnewpassword',
            'password_confirmation' => 'mismatch',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_password_change_requires_authentication(): void
    {
        $this->putJson('/api/user/password', [
            'current_password' => 'x',
            'password' => 'yyyyyyyy',
            'password_confirmation' => 'yyyyyyyy',
        ])->assertStatus(401);
    }
}
