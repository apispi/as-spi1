<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssertionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_evaluates_assertions_against_a_response(): void
    {
        $response = $this->actingAs(User::factory()->create())->postJson('/api/assertions/evaluate', [
            'assertions' => [
                ['path' => 'status', 'operator' => 'equals', 'expected' => 200],
                ['path' => 'data.id', 'operator' => 'exists'],
                ['path' => 'data.id', 'operator' => 'equals', 'expected' => 99],
            ],
            'response' => [
                'status' => 200,
                'time_ms' => 12,
                'headers' => [],
                'body' => '{"data":{"id":7}}',
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('passed', false)
            ->assertJsonPath('passed_count', 2)
            ->assertJsonPath('failed_count', 1)
            ->assertJsonPath('results.2.actual', 7);
    }

    public function test_it_rejects_operators_outside_the_vocabulary(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/api/assertions/evaluate', [
            'assertions' => [['path' => 'status', 'operator' => 'includes', 'expected' => 200]],
            'response' => ['status' => 200],
        ])->assertStatus(422)->assertJsonValidationErrors(['assertions.0.operator']);
    }

    public function test_it_attaches_assertions_to_a_saved_request(): void
    {
        $user = User::factory()->create();
        $saved = $user->savedRequests()->create([
            'name' => 'Users', 'protocol' => 'rest', 'method' => 'GET',
            'url' => 'https://api.example.com/users',
        ]);

        $this->actingAs($user)->putJson("/api/saved-requests/{$saved->id}/assertions", [
            'assertions' => [
                ['path' => 'status', 'operator' => 'equals', 'expected' => '200', 'description' => 'OK'],
            ],
        ])->assertOk();

        $this->assertCount(1, $saved->fresh()->assertions);
        $this->assertSame('equals', $saved->fresh()->assertions[0]['operator']);
    }

    public function test_assertions_can_be_cleared(): void
    {
        $user = User::factory()->create();
        $saved = $user->savedRequests()->create([
            'name' => 'Users', 'protocol' => 'rest', 'method' => 'GET',
            'url' => 'https://api.example.com/users',
            'assertions' => [['path' => 'status', 'operator' => 'exists']],
        ]);

        $this->actingAs($user)->putJson("/api/saved-requests/{$saved->id}/assertions", [
            'assertions' => [],
        ])->assertOk();

        $this->assertSame([], $saved->fresh()->assertions);
    }

    public function test_a_user_cannot_attach_assertions_to_another_users_request(): void
    {
        $owner = User::factory()->create();
        $saved = $owner->savedRequests()->create([
            'name' => 'Theirs', 'protocol' => 'rest', 'method' => 'GET',
            'url' => 'https://api.example.com/users',
        ]);

        $this->actingAs(User::factory()->create())
            ->putJson("/api/saved-requests/{$saved->id}/assertions", ['assertions' => []])
            ->assertStatus(404);
    }

    public function test_saved_requests_round_trip_their_assertions(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/saved-requests', [
            'name' => 'With assertions',
            'protocol' => 'rest',
            'method' => 'GET',
            'url' => 'https://api.example.com/users',
            'assertions' => [['path' => 'status', 'operator' => 'equals', 'expected' => '200']],
        ])->assertStatus(201);

        $this->actingAs($user)->getJson('/api/saved-requests')
            ->assertOk()
            ->assertJsonPath('0.assertions.0.path', 'status');
    }

    public function test_it_requires_authentication(): void
    {
        $this->postJson('/api/assertions/evaluate', [
            'assertions' => [['path' => 'status', 'operator' => 'exists']],
            'response' => ['status' => 200],
        ])->assertStatus(401);
    }
}
