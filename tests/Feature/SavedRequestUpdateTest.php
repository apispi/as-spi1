<?php

namespace Tests\Feature;

use App\Models\Organisation;
use App\Models\SavedRequest;
use App\Models\User;
use App\Models\WorkspaceActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavedRequestUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function savedRequest(User $user, array $overrides = []): SavedRequest
    {
        return $user->savedRequests()->create(array_merge([
            'name' => 'List users',
            'protocol' => 'rest',
            'method' => 'GET',
            'url' => 'https://api.example.com/users',
            'headers' => ['Accept' => 'application/json'],
        ], $overrides));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'List people',
            'method' => 'POST',
            'url' => 'https://api.example.com/people',
        ], $overrides);
    }

    public function test_a_saved_request_can_be_edited_in_place(): void
    {
        $user = User::factory()->create();
        $saved = $this->savedRequest($user);

        $this->actingAs($user)->putJson("/api/saved-requests/{$saved->id}", $this->payload())
            ->assertOk()
            ->assertJsonPath('name', 'List people')
            ->assertJsonPath('url', 'https://api.example.com/people');

        $saved->refresh();
        $this->assertSame('POST', $saved->method);
        // Same row, so anything pointing at it still points at it.
        $this->assertSame(1, SavedRequest::where('user_id', $user->id)->count());
    }

    public function test_a_collection_step_survives_an_edit(): void
    {
        // The whole reason this endpoint exists: before it, changing a URL
        // meant delete-and-re-save, which took the id — and the step — with it.
        $user = User::factory()->create();
        $saved = $this->savedRequest($user);
        $collection = $user->collections()->create(['name' => 'Smoke']);
        $step = $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => 0]);

        $this->actingAs($user)->putJson("/api/saved-requests/{$saved->id}", $this->payload())->assertOk();

        $this->assertSame($saved->id, $step->fresh()->saved_request_id);
        $this->assertSame('List people', $collection->fresh()->steps->first()->savedRequest->name);
    }

    public function test_editing_does_not_count_against_the_saved_request_limit(): void
    {
        $user = User::factory()->create();
        $saved = $this->savedRequest($user);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->putJson("/api/saved-requests/{$saved->id}", $this->payload([
                'name' => 'Rename '.$i,
            ]))->assertOk();
        }

        $this->assertSame(1, SavedRequest::where('user_id', $user->id)->count());
    }

    public function test_fields_left_out_are_not_cleared(): void
    {
        // Assertions, the contract and the snapshot each have their own
        // endpoint; a rename must not wipe them.
        $user = User::factory()->create();
        $saved = $this->savedRequest($user, [
            'assertions' => [['path' => 'status', 'operator' => 'equals', 'expected' => '200']],
            'contract' => ['type' => 'object'],
        ]);

        $this->actingAs($user)->putJson("/api/saved-requests/{$saved->id}", [
            'name' => 'Renamed',
            'method' => 'GET',
            'url' => 'https://api.example.com/users',
        ])->assertOk();

        $saved->refresh();
        $this->assertSame('Renamed', $saved->name);
        $this->assertCount(1, $saved->assertions);
        $this->assertSame(['type' => 'object'], $saved->contract);
        // …and what *was* sent is applied.
        $this->assertSame(['Accept' => 'application/json'], $saved->headers);
    }

    public function test_headers_can_be_cleared_deliberately(): void
    {
        $user = User::factory()->create();
        $saved = $this->savedRequest($user);

        $this->actingAs($user)->putJson("/api/saved-requests/{$saved->id}", $this->payload([
            'headers' => [],
        ]))->assertOk();

        $this->assertSame([], $saved->fresh()->headers);
    }

    public function test_auth_can_be_edited(): void
    {
        $user = User::factory()->create();
        $saved = $this->savedRequest($user);

        $this->actingAs($user)->putJson("/api/saved-requests/{$saved->id}", $this->payload([
            'auth' => ['scheme' => 'bearer', 'token' => '{{api_token}}'],
        ]))->assertOk();

        $this->assertSame(['scheme' => 'bearer', 'token' => '{{api_token}}'], $saved->fresh()->auth);
    }

    public function test_a_templated_url_is_still_accepted(): void
    {
        $user = User::factory()->create();
        $saved = $this->savedRequest($user);

        $this->actingAs($user)->putJson("/api/saved-requests/{$saved->id}", $this->payload([
            'url' => 'https://{{host}}/users/{{id}}',
        ]))->assertOk();

        $this->assertSame('https://{{host}}/users/{{id}}', $saved->fresh()->url);
    }

    public function test_the_same_validation_applies_as_on_create(): void
    {
        $user = User::factory()->create();
        $saved = $this->savedRequest($user);

        $this->actingAs($user)->putJson("/api/saved-requests/{$saved->id}", [
            'name' => '', 'method' => 'GET', 'url' => 'https://api.example.com/x',
        ])->assertStatus(422)->assertJsonValidationErrors('name');

        $this->actingAs($user)->putJson("/api/saved-requests/{$saved->id}", $this->payload([
            'auth' => ['scheme' => 'hawk'],
        ]))->assertStatus(422)->assertJsonValidationErrors('auth.scheme');
    }

    public function test_the_edit_shows_up_in_the_activity_feed_and_can_be_undone(): void
    {
        $user = User::factory()->create();
        $saved = $this->savedRequest($user);

        $this->actingAs($user)->putJson("/api/saved-requests/{$saved->id}", $this->payload())->assertOk();

        $entry = WorkspaceActivity::where('action', 'updated')->latest('id')->firstOrFail();
        $this->assertSame('saved_request', $entry->subject_type);
        $this->assertSame('https://api.example.com/users', $entry->before['url']);

        $this->actingAs($user)->postJson("/api/workspace/activity/{$entry->id}/restore")->assertOk();
        $this->assertSame('https://api.example.com/users', $saved->fresh()->url);
    }

    public function test_a_colleague_can_edit_a_shared_request_but_a_stranger_cannot(): void
    {
        $owner = User::factory()->create();
        $colleague = User::factory()->create();
        $organisation = Organisation::create([
            'name' => 'Acme', 'slug' => 'acme', 'owner_user_id' => $owner->id,
        ]);
        $owner->update(['organisation_id' => $organisation->id]);
        $colleague->update(['organisation_id' => $organisation->id]);

        $saved = $this->savedRequest($owner);

        $this->actingAs($colleague->fresh())
            ->putJson("/api/saved-requests/{$saved->id}", $this->payload())
            ->assertOk();

        $this->actingAs(User::factory()->create())
            ->putJson("/api/saved-requests/{$saved->id}", $this->payload(['name' => 'Hijacked']))
            ->assertNotFound();

        $this->assertNotSame('Hijacked', $saved->fresh()->name);
    }

    public function test_editing_requires_authentication(): void
    {
        $user = User::factory()->create();
        $saved = $this->savedRequest($user);

        $this->putJson("/api/saved-requests/{$saved->id}", $this->payload())->assertUnauthorized();
    }
}
