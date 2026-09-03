<?php

namespace Tests\Feature;

use App\Models\SavedRequest;
use App\Models\User;
use App\Services\Snapshots\SnapshotDiffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function saved(User $user): SavedRequest
    {
        return $user->savedRequests()->create([
            'name' => 'Get widget', 'protocol' => 'rest', 'method' => 'GET',
            'url' => 'https://api.example.com/widget',
        ]);
    }

    public function test_capturing_stores_a_golden_snapshot(): void
    {
        $user = User::factory()->create();
        $saved = $this->saved($user);

        $this->actingAs($user)->putJson("/api/saved-requests/{$saved->id}/snapshot", [
            'status' => 200,
            'body' => '{"id": 1, "name": "Widget"}',
        ])->assertOk()->assertJsonPath('has_snapshot', true)->assertJsonPath('status', 200);

        $this->assertNotNull($saved->fresh()->snapshot_taken_at);
        $this->assertSame(200, $saved->fresh()->snapshot['status']);
    }

    public function test_an_unchanged_response_matches(): void
    {
        $user = User::factory()->create();
        $saved = $this->saved($user);
        $body = '{"id": 1, "name": "Widget", "tags": ["a", "b"]}';

        $this->actingAs($user)->putJson("/api/saved-requests/{$saved->id}/snapshot", ['status' => 200, 'body' => $body]);

        $this->actingAs($user)->postJson("/api/saved-requests/{$saved->id}/snapshot/check", ['status' => 200, 'body' => $body])
            ->assertOk()
            ->assertJsonPath('matches', true);
    }

    public function test_a_changed_value_is_reported(): void
    {
        $user = User::factory()->create();
        $saved = $this->saved($user);

        $this->actingAs($user)->putJson("/api/saved-requests/{$saved->id}/snapshot", [
            'status' => 200, 'body' => '{"id": 1, "name": "Widget", "price": 10}',
        ]);

        $res = $this->actingAs($user)->postJson("/api/saved-requests/{$saved->id}/snapshot/check", [
            'status' => 200, 'body' => '{"id": 1, "name": "Gadget", "price": 10}',
        ])->assertStatus(422)->assertJsonPath('matches', false);

        $changed = collect($res->json('changed'));
        $this->assertTrue($changed->contains(fn ($c) => $c['path'] === '$.name' && $c['from'] === 'Widget' && $c['to'] === 'Gadget'));
        $this->assertSame(0, $res->json('added_count'));
        $this->assertSame(0, $res->json('removed_count'));
    }

    public function test_added_and_removed_fields_are_reported(): void
    {
        $user = User::factory()->create();
        $saved = $this->saved($user);

        $this->actingAs($user)->putJson("/api/saved-requests/{$saved->id}/snapshot", [
            'status' => 200, 'body' => '{"id": 1, "legacy": true}',
        ]);

        $res = $this->actingAs($user)->postJson("/api/saved-requests/{$saved->id}/snapshot/check", [
            'status' => 200, 'body' => '{"id": 1, "created_at": "2026-01-01"}',
        ])->assertStatus(422);

        $this->assertTrue(collect($res->json('removed'))->contains(fn ($c) => $c['path'] === '$.legacy'));
        $this->assertTrue(collect($res->json('added'))->contains(fn ($c) => $c['path'] === '$.created_at'));
    }

    public function test_a_status_change_alone_fails_the_check(): void
    {
        $user = User::factory()->create();
        $saved = $this->saved($user);
        $body = '{"ok": true}';

        $this->actingAs($user)->putJson("/api/saved-requests/{$saved->id}/snapshot", ['status' => 200, 'body' => $body]);

        $this->actingAs($user)->postJson("/api/saved-requests/{$saved->id}/snapshot/check", ['status' => 500, 'body' => $body])
            ->assertStatus(422)
            ->assertJsonPath('status_changed', true)
            ->assertJsonPath('status_from', 200)
            ->assertJsonPath('status_to', 500);
    }

    public function test_check_persists_a_snapshot_report(): void
    {
        $user = User::factory()->create();
        $saved = $this->saved($user);

        $this->actingAs($user)->putJson("/api/saved-requests/{$saved->id}/snapshot", ['status' => 200, 'body' => '{"a": 1}']);
        $res = $this->actingAs($user)->postJson("/api/saved-requests/{$saved->id}/snapshot/check", ['status' => 200, 'body' => '{"a": 2}']);

        $this->assertDatabaseHas('inspection_reports', ['id' => $res->json('report_id'), 'type' => 'snapshot']);
    }

    public function test_check_without_a_snapshot_is_rejected(): void
    {
        $user = User::factory()->create();
        $saved = $this->saved($user);

        $this->actingAs($user)->postJson("/api/saved-requests/{$saved->id}/snapshot/check", ['status' => 200, 'body' => '{}'])
            ->assertStatus(422);
    }

    public function test_clearing_removes_the_snapshot(): void
    {
        $user = User::factory()->create();
        $saved = $this->saved($user);

        $this->actingAs($user)->putJson("/api/saved-requests/{$saved->id}/snapshot", ['status' => 200, 'body' => '{"a": 1}']);
        $this->actingAs($user)->putJson("/api/saved-requests/{$saved->id}/snapshot", ['body' => ''])
            ->assertOk()->assertJsonPath('has_snapshot', false);

        $this->assertNull($saved->fresh()->snapshot);
    }

    public function test_snapshots_are_workspace_scoped(): void
    {
        $owner = User::factory()->create();
        $saved = $this->saved($owner);

        $this->actingAs(User::factory()->create())
            ->putJson("/api/saved-requests/{$saved->id}/snapshot", ['status' => 200, 'body' => '{}'])
            ->assertStatus(404);
    }

    public function test_differ_flags_type_and_nested_changes(): void
    {
        $differ = new SnapshotDiffer;
        $diff = $differ->compare(
            ['status' => 200, 'body' => '{"n": 1, "child": {"flag": true}}'],
            ['status' => 200, 'body' => '{"n": "1", "child": {"flag": false}}'],
        );

        $this->assertFalse($diff['matches']);
        $paths = collect($diff['changed'])->pluck('path')->all();
        $this->assertContains('$.n', $paths);          // 1 (int) vs "1" (string)
        $this->assertContains('$.child.flag', $paths);  // nested value change
    }

    public function test_differ_compares_non_json_bodies_raw(): void
    {
        $differ = new SnapshotDiffer;
        $same = $differ->compare(['status' => 200, 'body' => 'OK'], ['status' => 200, 'body' => 'OK']);
        $this->assertTrue($same['matches']);

        $diff = $differ->compare(['status' => 200, 'body' => 'OK'], ['status' => 200, 'body' => 'FAIL']);
        $this->assertFalse($diff['matches']);
    }
}
