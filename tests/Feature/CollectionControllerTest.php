<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectionControllerTest extends TestCase
{
    use RefreshDatabase;

    private function saved(User $user, string $name = 'Step'): \App\Models\SavedRequest
    {
        return $user->savedRequests()->create([
            'name' => $name, 'protocol' => 'rest', 'method' => 'GET',
            'url' => 'https://api.example.com/'.strtolower($name),
        ]);
    }

    public function test_it_creates_a_collection_with_ordered_steps(): void
    {
        $user = User::factory()->create();
        $a = $this->saved($user, 'Login');
        $b = $this->saved($user, 'Me');

        $response = $this->actingAs($user)->postJson('/api/collections', [
            'name' => 'Smoke',
            'description' => 'Happy path',
            'steps' => [
                ['saved_request_id' => $b->id],
                ['saved_request_id' => $a->id, 'extract' => [['name' => 'token', 'path' => 'token']]],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'Smoke')
            ->assertJsonPath('steps.0.saved_request_id', $b->id)
            ->assertJsonPath('steps.1.saved_request_id', $a->id)
            ->assertJsonPath('steps.1.extract.0.name', 'token');
    }

    public function test_updating_replaces_the_step_list(): void
    {
        $user = User::factory()->create();
        $a = $this->saved($user, 'One');
        $b = $this->saved($user, 'Two');

        $collection = $user->collections()->create(['name' => 'Suite']);
        $collection->steps()->create(['saved_request_id' => $a->id, 'position' => 0]);

        $this->actingAs($user)->putJson("/api/collections/{$collection->id}", [
            'name' => 'Suite',
            'steps' => [['saved_request_id' => $b->id]],
        ])->assertOk()->assertJsonCount(1, 'steps')
            ->assertJsonPath('steps.0.saved_request_id', $b->id);
    }

    public function test_it_ignores_steps_referencing_another_users_saved_request(): void
    {
        $user = User::factory()->create();
        $mine = $this->saved($user, 'Mine');
        $theirs = $this->saved(User::factory()->create(), 'Theirs');

        $this->actingAs($user)->postJson('/api/collections', [
            'name' => 'Mixed',
            'steps' => [
                ['saved_request_id' => $mine->id],
                ['saved_request_id' => $theirs->id],
            ],
        ])->assertStatus(201)->assertJsonCount(1, 'steps');
    }

    public function test_deleting_a_saved_request_removes_its_step(): void
    {
        $user = User::factory()->create();
        $saved = $this->saved($user);
        $collection = $user->collections()->create(['name' => 'Suite']);
        $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => 0]);

        $this->actingAs($user)->deleteJson("/api/saved-requests/{$saved->id}")->assertOk();

        $this->assertSame(0, $collection->steps()->count());
    }

    public function test_it_rejects_duplicate_names_and_bad_variable_names(): void
    {
        $user = User::factory()->create();
        $user->collections()->create(['name' => 'Suite']);
        $saved = $this->saved($user);

        $this->actingAs($user)->postJson('/api/collections', ['name' => 'Suite'])
            ->assertStatus(422)->assertJsonValidationErrors(['name']);

        $this->actingAs($user)->postJson('/api/collections', [
            'name' => 'Other',
            'steps' => [['saved_request_id' => $saved->id, 'extract' => [['name' => 'bad name!', 'path' => 'x']]]],
        ])->assertStatus(422)->assertJsonValidationErrors(['steps.0.extract.0.name']);
    }

    public function test_a_user_only_sees_their_own_collections(): void
    {
        $owner = User::factory()->create();
        $owner->collections()->create(['name' => 'Theirs']);

        $this->actingAs(User::factory()->create())->getJson('/api/collections')
            ->assertOk()->assertJsonCount(0);
    }

    public function test_collections_require_authentication(): void
    {
        $this->getJson('/api/collections')->assertStatus(401);
    }
}
