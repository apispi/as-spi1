<?php

namespace Tests\Feature;

use App\Models\Environment;
use App\Models\Organisation;
use App\Models\SavedRequest;
use App\Models\User;
use App\Models\WorkspaceActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceActivityRestoreTest extends TestCase
{
    use RefreshDatabase;

    private function savedRequest(User $user): SavedRequest
    {
        return $user->savedRequests()->create([
            'name' => 'List users', 'protocol' => 'rest', 'method' => 'GET',
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

    private function lastUpdate(): WorkspaceActivity
    {
        return WorkspaceActivity::where('action', 'updated')->latest('id')->firstOrFail();
    }

    // -------------------------------------------------------------- capturing

    public function test_an_update_keeps_the_values_it_replaced(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $saved = $this->savedRequest($user);
        $saved->update(['url' => 'https://api.example.com/people', 'method' => 'POST']);

        $entry = $this->lastUpdate();
        $this->assertEqualsCanonicalizing(['url', 'method'], $entry->changed);
        $this->assertSame('https://api.example.com/users', $entry->before['url']);
        $this->assertSame('GET', $entry->before['method']);
    }

    public function test_a_delete_records_what_was_lost(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->savedRequest($user)->delete();

        $entry = WorkspaceActivity::where('action', 'deleted')->firstOrFail();
        $this->assertSame('https://api.example.com/users', $entry->before['url']);
    }

    public function test_a_create_stores_no_previous_values(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->savedRequest($user);

        $entry = WorkspaceActivity::where('action', 'created')->firstOrFail();
        $this->assertNull($entry->before);
    }

    public function test_an_oversized_snapshot_is_skipped_but_the_change_is_still_logged(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $saved = $this->savedRequest($user);
        $saved->update(['body' => str_repeat('x', 70000)]);
        WorkspaceActivity::query()->delete();

        // Replacing a 70KB body means the *previous* value is the big one.
        $saved->update(['body' => 'small']);

        $entry = $this->lastUpdate();
        $this->assertContains('body', $entry->changed, 'The change itself must still be recorded.');
        $this->assertNull($entry->before, 'The oversized snapshot is not worth storing.');
        $this->assertFalse($entry->isRestorable());
    }

    // ------------------------------------------------------------- redaction

    public function test_credential_bearing_values_are_never_sent_to_the_client(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $environment = $user->environments()->create([
            'name' => 'Staging',
            'variables' => [['key' => 'token', 'value' => 'super-secret-value', 'secret' => true]],
        ]);
        $environment->update(['variables' => [['key' => 'token', 'value' => 'rotated', 'secret' => true]]]);

        $response = $this->getJson('/api/workspace/activity')->assertOk();

        $this->assertStringNotContainsString('super-secret-value', $response->getContent());
        $this->assertSame('(hidden)', $response->json('activity.0.before.variables'));

        // …but it is still stored, so the undo can put it back.
        $this->assertSame('super-secret-value', $this->lastUpdate()->before['variables'][0]['value']);
    }

    public function test_a_long_value_is_truncated_for_display_only(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $saved = $this->savedRequest($user);
        $saved->update(['body' => str_repeat('a', 500)]);
        WorkspaceActivity::query()->delete();
        $saved->update(['body' => 'short']);

        $shown = $this->getJson('/api/workspace/activity')->json('activity.0.before.body');
        $this->assertLessThan(500, mb_strlen($shown));
        $this->assertStringEndsWith('…', $shown);
        $this->assertSame(500, mb_strlen($this->lastUpdate()->before['body']));
    }

    // -------------------------------------------------------------- restoring

    public function test_an_update_can_be_undone(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $saved = $this->savedRequest($user);
        $saved->update(['url' => 'https://api.example.com/people', 'method' => 'POST']);

        $this->postJson('/api/workspace/activity/'.$this->lastUpdate()->id.'/restore')
            ->assertOk()
            ->assertJsonPath('restored', fn ($r) => in_array('url', $r, true));

        $saved->refresh();
        $this->assertSame('https://api.example.com/users', $saved->url);
        $this->assertSame('GET', $saved->method);
    }

    public function test_a_restore_only_touches_the_fields_that_entry_changed(): void
    {
        // Undoing an old URL change must not also revert the rename that
        // happened afterwards.
        $user = User::factory()->create();
        $this->actingAs($user);

        $saved = $this->savedRequest($user);
        $saved->update(['url' => 'https://api.example.com/people']);
        $urlChange = $this->lastUpdate();
        $saved->update(['name' => 'Renamed later']);

        $this->postJson("/api/workspace/activity/{$urlChange->id}/restore")->assertOk();

        $saved->refresh();
        $this->assertSame('https://api.example.com/users', $saved->url);
        $this->assertSame('Renamed later', $saved->name, 'The later rename must survive.');
    }

    public function test_restoring_a_secret_value_puts_the_real_one_back(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $environment = $user->environments()->create([
            'name' => 'Staging',
            'variables' => [['key' => 'token', 'value' => 'super-secret-value', 'secret' => true]],
        ]);
        $environment->update(['variables' => [['key' => 'token', 'value' => 'rotated', 'secret' => true]]]);

        $this->postJson('/api/workspace/activity/'.$this->lastUpdate()->id.'/restore')->assertOk();

        $this->assertSame('super-secret-value', $environment->fresh()->variables[0]['value']);
    }

    public function test_a_restore_is_itself_logged_and_can_be_undone(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $saved = $this->savedRequest($user);
        $saved->update(['url' => 'https://api.example.com/people']);
        $original = $this->lastUpdate();

        $this->postJson("/api/workspace/activity/{$original->id}/restore")->assertOk();

        $undo = $this->lastUpdate();
        $this->assertNotSame($original->id, $undo->id, 'The restore is a change of its own.');
        $this->assertSame('https://api.example.com/people', $undo->before['url']);

        // …and undoing the undo gets back to where we were.
        $this->postJson("/api/workspace/activity/{$undo->id}/restore")->assertOk();
        $this->assertSame('https://api.example.com/people', $saved->fresh()->url);
    }

    public function test_a_colleague_can_undo_a_change_in_the_shared_workspace(): void
    {
        $me = User::factory()->create();
        $colleague = User::factory()->create();
        $this->teamOf($me, $colleague);

        $saved = $this->savedRequest($me);
        $this->actingAs($colleague);
        $saved->update(['url' => 'https://api.example.com/oops']);

        $this->actingAs($me->fresh())
            ->postJson('/api/workspace/activity/'.$this->lastUpdate()->id.'/restore')
            ->assertOk();

        $this->assertSame('https://api.example.com/users', $saved->fresh()->url);
    }

    public function test_an_entry_from_another_workspace_is_not_found(): void
    {
        $stranger = User::factory()->create();
        $this->actingAs($stranger);
        $saved = $this->savedRequest($stranger);
        $saved->update(['url' => 'https://api.example.com/theirs']);
        $entry = $this->lastUpdate();

        $me = User::factory()->create();
        $this->actingAs($me)->postJson("/api/workspace/activity/{$entry->id}/restore")
            ->assertNotFound();

        $this->assertSame('https://api.example.com/theirs', $saved->fresh()->url);
    }

    public function test_a_delete_is_refused_with_an_explanation_rather_than_half_restored(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->savedRequest($user)->delete();

        $entry = WorkspaceActivity::where('action', 'deleted')->firstOrFail();

        $this->postJson("/api/workspace/activity/{$entry->id}/restore")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'cannot be restored automatically'));
    }

    public function test_a_create_has_nothing_to_undo(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->savedRequest($user);

        $entry = WorkspaceActivity::where('action', 'created')->firstOrFail();

        $this->postJson("/api/workspace/activity/{$entry->id}/restore")
            ->assertStatus(422)
            ->assertJsonPath('message', 'There is nothing to undo for this entry.');
    }

    public function test_restoring_onto_a_since_deleted_resource_is_reported_clearly(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $saved = $this->savedRequest($user);
        $saved->update(['url' => 'https://api.example.com/people']);
        $entry = $this->lastUpdate();
        $saved->delete();

        $this->postJson("/api/workspace/activity/{$entry->id}/restore")
            ->assertNotFound()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'no longer exists'));
    }

    public function test_a_restore_is_audited(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $saved = $this->savedRequest($user);
        $saved->update(['url' => 'https://api.example.com/people']);

        $this->postJson('/api/workspace/activity/'.$this->lastUpdate()->id.'/restore')->assertOk();

        $this->assertDatabaseHas('audit_events', [
            'user_id' => $user->id,
            'action' => 'workspace.change_restored',
        ]);
    }

    public function test_restoring_requires_authentication(): void
    {
        $this->postJson('/api/workspace/activity/1/restore')->assertUnauthorized();
    }
}
