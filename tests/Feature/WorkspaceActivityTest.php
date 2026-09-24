<?php

namespace Tests\Feature;

use App\Models\Monitor;
use App\Models\Organisation;
use App\Models\SavedRequest;
use App\Models\User;
use App\Models\WorkspaceActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceActivityTest extends TestCase
{
    use RefreshDatabase;

    private function savedRequest(User $user, string $name = 'List users'): SavedRequest
    {
        return $user->savedRequests()->create([
            'name' => $name, 'protocol' => 'rest', 'method' => 'GET',
            'url' => 'https://api.example.com/users',
        ]);
    }

    private function teamOf(User ...$members): Organisation
    {
        $organisation = Organisation::create([
            'name' => 'Acme', 'slug' => 'acme', 'owner_user_id' => $members[0]->id,
        ]);
        foreach ($members as $member) {
            $member->update(['organisation_id' => $organisation->id]);
        }

        return $organisation;
    }

    public function test_creating_updating_and_deleting_are_all_recorded(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $saved = $this->savedRequest($user);
        $saved->update(['url' => 'https://api.example.com/people']);
        $saved->delete();

        $actions = WorkspaceActivity::orderBy('id')->pluck('action')->all();
        $this->assertSame(['created', 'updated', 'deleted'], $actions);

        $entry = WorkspaceActivity::orderBy('id')->first();
        $this->assertSame('saved_request', $entry->subject_type);
        $this->assertSame('List users', $entry->subject_name);
        $this->assertSame($user->id, $entry->user_id);
    }

    public function test_an_update_names_the_fields_that_changed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $saved = $this->savedRequest($user);
        $saved->update(['method' => 'POST', 'url' => 'https://api.example.com/people']);

        $entry = WorkspaceActivity::where('action', 'updated')->firstOrFail();
        $this->assertStringContainsString('method', $entry->summary);
        $this->assertStringContainsString('url', $entry->summary);
    }

    public function test_the_deleted_entry_keeps_the_name_the_row_no_longer_has(): void
    {
        // The whole point of denormalising the name: the log outlives its
        // subject, and "deleted request #17" would be useless.
        $user = User::factory()->create();
        $this->actingAs($user);

        $saved = $this->savedRequest($user, 'Checkout smoke');
        $id = $saved->id;
        $saved->delete();

        $entry = WorkspaceActivity::where('action', 'deleted')->firstOrFail();
        $this->assertSame('Checkout smoke', $entry->subject_name);
        $this->assertSame($id, $entry->subject_id);
        $this->assertNull(SavedRequest::find($id));
    }

    public function test_nothing_is_recorded_without_a_signed_in_actor(): void
    {
        // Seeders, commands and the scheduler all write to these tables.
        $user = User::factory()->create();
        $this->savedRequest($user);

        $this->assertSame(0, WorkspaceActivity::count());
    }

    public function test_a_monitor_run_updating_its_own_bookkeeping_is_not_activity(): void
    {
        // A monitor writes last_run_at and last_status on every tick; logging
        // that would drown everything a person actually did.
        $user = User::factory()->create();
        $this->actingAs($user);

        $monitor = $user->monitors()->create(['name' => 'Checkout', 'interval_minutes' => 60]);
        WorkspaceActivity::query()->delete();

        $monitor->update([
            'last_run_at' => now(),
            'last_status' => Monitor::STATUS_PASSING,
            'consecutive_failures' => 0,
        ]);

        $this->assertSame(0, WorkspaceActivity::count(), 'A scheduled run is not an edit.');
    }

    public function test_a_real_monitor_edit_is_still_recorded(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $monitor = $user->monitors()->create(['name' => 'Checkout', 'interval_minutes' => 60]);
        WorkspaceActivity::query()->delete();

        $monitor->update(['interval_minutes' => 15, 'last_run_at' => now()]);

        $entry = WorkspaceActivity::firstOrFail();
        $this->assertSame('monitor', $entry->subject_type);
        $this->assertStringContainsString('interval_minutes', $entry->summary);
        // The bookkeeping column rode along but is not mentioned.
        $this->assertStringNotContainsString('last_run_at', $entry->summary);
    }

    public function test_capturing_a_snapshot_is_not_logged_as_an_edit(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $saved = $this->savedRequest($user);
        WorkspaceActivity::query()->delete();

        $saved->update(['snapshot' => ['status' => 200], 'snapshot_taken_at' => now()]);

        $this->assertSame(0, WorkspaceActivity::count());
    }

    public function test_an_update_that_changes_nothing_records_nothing(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $saved = $this->savedRequest($user);
        WorkspaceActivity::query()->delete();

        $saved->update(['name' => 'List users']); // same value

        $this->assertSame(0, WorkspaceActivity::count());
    }

    public function test_the_feed_shows_what_colleagues_did(): void
    {
        $me = User::factory()->create(['name' => 'Ada']);
        $colleague = User::factory()->create(['name' => 'Grace']);
        $this->teamOf($me, $colleague);

        $this->actingAs($colleague);
        $this->savedRequest($colleague, 'Grace\'s request');

        $this->actingAs($me->fresh())->getJson('/api/workspace/activity')
            ->assertOk()
            ->assertJsonPath('activity.0.actor', 'Grace')
            ->assertJsonPath('activity.0.action', 'created')
            ->assertJsonPath('activity.0.subject_label', 'request')
            ->assertJsonPath('activity.0.subject_name', "Grace's request");
    }

    public function test_the_feed_does_not_leak_across_workspaces(): void
    {
        $me = User::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger);
        $this->savedRequest($stranger, 'Not yours');

        $this->actingAs($me)->getJson('/api/workspace/activity')
            ->assertOk()
            ->assertJsonCount(0, 'activity');
    }

    public function test_activity_stops_being_visible_after_someone_leaves(): void
    {
        $owner = User::factory()->create();
        $leaver = User::factory()->create();
        $this->teamOf($owner, $leaver);

        $this->actingAs($leaver);
        $this->savedRequest($leaver, 'Theirs');

        $this->actingAs($owner->fresh())->getJson('/api/workspace/activity')
            ->assertJsonCount(1, 'activity');

        $leaver->update(['organisation_id' => null]);

        $this->actingAs($owner->fresh())->getJson('/api/workspace/activity')
            ->assertJsonCount(0, 'activity');
    }

    public function test_the_feed_can_be_filtered_and_limited(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->savedRequest($user, 'One');
        $user->collections()->create(['name' => 'A collection']);

        $this->getJson('/api/workspace/activity?type=collection')
            ->assertOk()
            ->assertJsonCount(1, 'activity')
            ->assertJsonPath('activity.0.subject_type', 'collection');

        $this->getJson('/api/workspace/activity?limit=1')
            ->assertOk()
            ->assertJsonCount(1, 'activity');
    }

    public function test_the_log_is_bounded_per_actor(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < WorkspaceActivity::KEEP_PER_USER + 5; $i++) {
            WorkspaceActivity::record($user->id, 'saved_request', $i + 1, 'R'.$i, 'created');
        }

        $this->assertSame(WorkspaceActivity::KEEP_PER_USER, WorkspaceActivity::where('user_id', $user->id)->count());
        // The oldest were the ones dropped.
        $this->assertSame('R'.(WorkspaceActivity::KEEP_PER_USER + 4), WorkspaceActivity::latest('id')->first()->subject_name);
    }

    public function test_the_feed_requires_authentication(): void
    {
        $this->getJson('/api/workspace/activity')->assertUnauthorized();
    }
}
