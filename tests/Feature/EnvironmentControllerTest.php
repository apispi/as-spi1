<?php

namespace Tests\Feature;

use App\Models\Environment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnvironmentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_an_environment_with_variables(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/environments', [
            'name' => 'Staging',
            'is_default' => true,
            'variables' => [
                ['key' => 'base_url', 'value' => 'https://staging.example.com', 'secret' => false],
                ['key' => 'token', 'value' => 'sk-staging-123', 'secret' => true],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'Staging')
            ->assertJsonPath('is_default', true)
            ->assertJsonPath('variables.0.value', 'https://staging.example.com');

        // The secret's value is never returned, only the fact that it is set.
        $response->assertJsonPath('variables.1.value', '')
            ->assertJsonPath('variables.1.secret', true)
            ->assertJsonPath('variables.1.has_value', true);

        $this->assertSame('sk-staging-123', Environment::first()->map()['token']);
    }

    public function test_updating_without_resending_a_secret_keeps_the_stored_value(): void
    {
        $user = User::factory()->create();
        $env = $user->environments()->create([
            'name' => 'Prod',
            'variables' => [['key' => 'token', 'value' => 'sk-live-999', 'secret' => true]],
        ]);

        $this->actingAs($user)->putJson("/api/environments/{$env->id}", [
            'name' => 'Prod',
            'variables' => [['key' => 'token', 'value' => '', 'secret' => true]],
        ])->assertOk();

        $this->assertSame('sk-live-999', $env->fresh()->map()['token']);
    }

    public function test_only_one_environment_is_default(): void
    {
        $user = User::factory()->create();
        $first = $user->environments()->create(['name' => 'A', 'variables' => [], 'is_default' => true]);

        $this->actingAs($user)->postJson('/api/environments', [
            'name' => 'B',
            'is_default' => true,
            'variables' => [],
        ])->assertStatus(201);

        $this->assertFalse($first->fresh()->is_default);
    }

    public function test_rejects_duplicate_names_and_invalid_variable_names(): void
    {
        $user = User::factory()->create();
        $user->environments()->create(['name' => 'Staging', 'variables' => []]);

        $this->actingAs($user)->postJson('/api/environments', [
            'name' => 'Staging',
            'variables' => [],
        ])->assertStatus(422)->assertJsonValidationErrors(['name']);

        $this->actingAs($user)->postJson('/api/environments', [
            'name' => 'Other',
            'variables' => [['key' => 'bad name!', 'value' => 'x']],
        ])->assertStatus(422)->assertJsonValidationErrors(['variables.0.key']);
    }

    public function test_a_user_cannot_read_or_delete_another_users_environment(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $env = $owner->environments()->create(['name' => 'Mine', 'variables' => []]);

        $this->actingAs($other)->getJson('/api/environments')
            ->assertOk()
            ->assertJsonCount(0);

        $this->actingAs($other)->deleteJson("/api/environments/{$env->id}")->assertStatus(404);
    }

    public function test_environments_require_authentication(): void
    {
        $this->getJson('/api/environments')->assertStatus(401);
    }
}
