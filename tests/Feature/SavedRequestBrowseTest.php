<?php

namespace Tests\Feature;

use App\Models\SavedRequest;
use App\Models\User;
use App\Models\WorkspaceActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavedRequestBrowseTest extends TestCase
{
    use RefreshDatabase;

    private function savedRequest(User $user, array $overrides = []): SavedRequest
    {
        return $user->savedRequests()->create(array_merge([
            'name' => 'List users',
            'protocol' => 'rest',
            'method' => 'GET',
            'url' => 'https://api.example.com/users',
        ], $overrides));
    }

    public function test_the_list_says_which_collections_use_each_request(): void
    {
        // Without this the only way to find out was to delete it and see what
        // broke.
        $user = User::factory()->create();
        $used = $this->savedRequest($user);
        $unused = $this->savedRequest($user, ['name' => 'Spare']);

        foreach (['Smoke', 'Nightly'] as $name) {
            $collection = $user->collections()->create(['name' => $name]);
            $collection->steps()->create(['saved_request_id' => $used->id, 'position' => 0]);
        }

        $rows = collect($this->actingAs($user)->getJson('/api/saved-requests')->assertOk()->json());

        $this->assertEqualsCanonicalizing(
            ['Smoke', 'Nightly'],
            $rows->firstWhere('id', $used->id)['used_by']
        );
        $this->assertSame([], $rows->firstWhere('id', $unused->id)['used_by']);
    }

    public function test_a_request_used_twice_by_one_collection_names_it_once(): void
    {
        $user = User::factory()->create();
        $saved = $this->savedRequest($user);
        $collection = $user->collections()->create(['name' => 'Smoke']);
        $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => 0]);
        $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => 1]);

        $row = collect($this->actingAs($user)->getJson('/api/saved-requests')->json())->first();

        $this->assertSame(['Smoke'], $row['used_by']);
    }

    public function test_the_list_flags_what_is_attached_to_each_request(): void
    {
        $user = User::factory()->create();
        $this->savedRequest($user, [
            'name' => 'Rich',
            'assertions' => [['path' => 'status', 'operator' => 'equals', 'expected' => '200']],
            'contract' => ['type' => 'object'],
            'auth' => ['scheme' => 'bearer', 'token' => '{{t}}'],
        ]);
        $this->savedRequest($user, ['name' => 'Bare']);

        $rows = collect($this->actingAs($user)->getJson('/api/saved-requests')->json())->keyBy('name');

        $this->assertTrue($rows['Rich']['has_assertions']);
        $this->assertTrue($rows['Rich']['has_contract']);
        $this->assertTrue($rows['Rich']['has_auth']);
        $this->assertFalse($rows['Bare']['has_assertions']);
        $this->assertFalse($rows['Bare']['has_auth']);
    }

    public function test_inheriting_auth_does_not_count_as_having_its_own(): void
    {
        // "inherit" means the environment supplies it, so flagging the request
        // as carrying auth would be misleading.
        $user = User::factory()->create();
        $this->savedRequest($user, ['auth' => ['scheme' => 'inherit']]);

        $row = collect($this->actingAs($user)->getJson('/api/saved-requests')->json())->first();

        $this->assertFalse($row['has_auth']);
    }

    public function test_every_endpoint_returns_the_same_shape(): void
    {
        // The list renders used_by and the flags; an endpoint that returned the
        // bare model would hand back a row that renders as unused.
        $user = User::factory()->create();

        $created = $this->actingAs($user)->postJson('/api/saved-requests', [
            'name' => 'New', 'method' => 'GET', 'url' => 'https://api.example.com/x',
        ])->assertCreated();
        $created->assertJsonStructure(['used_by', 'has_assertions', 'has_contract', 'has_auth']);

        $id = $created->json('id');

        $this->actingAs($user)->putJson("/api/saved-requests/{$id}", [
            'name' => 'Renamed', 'method' => 'GET', 'url' => 'https://api.example.com/x',
        ])->assertOk()->assertJsonStructure(['used_by', 'has_assertions']);

        $this->actingAs($user)->postJson("/api/saved-requests/{$id}/duplicate")
            ->assertCreated()->assertJsonStructure(['used_by', 'has_assertions']);
    }

    public function test_a_rename_keeps_the_usage_reported(): void
    {
        $user = User::factory()->create();
        $saved = $this->savedRequest($user);
        $collection = $user->collections()->create(['name' => 'Smoke']);
        $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => 0]);

        $this->actingAs($user)->putJson("/api/saved-requests/{$saved->id}", [
            'name' => 'Renamed', 'method' => 'GET', 'url' => 'https://api.example.com/users',
        ])->assertOk()->assertJsonPath('used_by.0', 'Smoke');
    }

    public function test_deleting_a_used_request_records_why_each_collection_got_shorter(): void
    {
        // The cascade removes steps without touching the collection row, so
        // nothing would otherwise appear in the activity feed.
        $user = User::factory()->create();
        $this->actingAs($user);

        $saved = $this->savedRequest($user);
        $collection = $user->collections()->create(['name' => 'Smoke']);
        $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => 0]);
        WorkspaceActivity::query()->delete();

        $this->deleteJson("/api/saved-requests/{$saved->id}")
            ->assertOk()
            ->assertJsonPath('removed_steps', 1)
            ->assertJsonPath('used_by.0', 'Smoke');

        $entry = WorkspaceActivity::where('subject_type', 'collection')->firstOrFail();
        $this->assertSame('Smoke', $entry->subject_name);
        $this->assertStringContainsString('lost a step', $entry->summary);
        $this->assertStringContainsString('List users', $entry->summary);
    }

    public function test_deleting_an_unused_request_reports_no_collateral(): void
    {
        $user = User::factory()->create();
        $saved = $this->savedRequest($user);

        $this->actingAs($user)->deleteJson("/api/saved-requests/{$saved->id}")
            ->assertOk()
            ->assertJsonPath('removed_steps', 0)
            ->assertJsonPath('used_by', []);
    }

    public function test_the_list_covers_the_whole_workspace(): void
    {
        $owner = User::factory()->create();
        $colleague = User::factory()->create();
        $organisation = \App\Models\Organisation::create([
            'name' => 'Acme', 'slug' => 'acme', 'owner_user_id' => $owner->id,
        ]);
        $owner->update(['organisation_id' => $organisation->id]);
        $colleague->update(['organisation_id' => $organisation->id]);

        $this->savedRequest($colleague, ['name' => 'Theirs']);

        $rows = collect($this->actingAs($owner->fresh())->getJson('/api/saved-requests')->json());

        $this->assertSame('Theirs', $rows->first()['name']);
        $this->assertSame($colleague->name, $rows->first()['owner']['name']);
    }
}
