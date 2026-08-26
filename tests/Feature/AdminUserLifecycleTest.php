<?php

namespace Tests\Feature;

use App\Models\AdminAction;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    /** A user who owns one of everything, to prove a hard delete reaches it all. */
    private function userWithData(): User
    {
        $user = User::factory()->create();

        $saved = $user->savedRequests()->create([
            'name' => 'Ping', 'protocol' => 'rest', 'method' => 'GET',
            'url' => 'https://api.example.com/ping',
        ]);
        $user->requestHistories()->create([
            'protocol' => 'rest', 'method' => 'GET',
            'url' => 'https://api.example.com/ping', 'status' => 200, 'time_ms' => 10,
        ]);
        $user->environments()->create(['name' => 'Staging', 'variables' => []]);
        $collection = $user->collections()->create(['name' => 'Smoke']);
        $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => 0]);
        $user->monitors()->create([
            'collection_id' => $collection->id, 'name' => 'Prod', 'interval_minutes' => 60,
        ]);
        $user->alertChannels()->create([
            'name' => 'Slack', 'type' => 'slack', 'url' => 'https://hooks.example.com/x/y',
        ]);
        \App\Models\InspectionReport::create([
            'user_id' => $user->id, 'type' => 'collection_run', 'summary' => 'x', 'data' => [],
        ]);

        return $user;
    }

    public function test_an_admin_can_create_a_user(): void
    {
        $org = Organisation::create(['name' => 'Acme', 'slug' => 'acme']);

        $this->actingAs($this->admin())->postJson('/api/admin/users', [
            'name' => 'Ada Lovelace',
            'email' => 'Ada@Example.com',
            'password' => 'correct-horse-battery',
            'is_admin' => true,
            'organisation_id' => $org->id,
        ])->assertStatus(201);

        $created = User::where('email', 'ada@example.com')->firstOrFail();
        $this->assertTrue($created->is_admin);
        $this->assertSame($org->id, $created->organisation_id);
        // Created by an admin, so the address counts as confirmed.
        $this->assertNotNull($created->email_verified_at);
        $this->assertTrue(Hash::check('correct-horse-battery', $created->password));
        $this->assertDatabaseHas('admin_actions', ['action' => 'create_user']);
    }

    public function test_creating_a_user_rejects_weak_and_crlf_bearing_input(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Short', 'email' => 'short@example.com', 'password' => 'tooshort',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);

        $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Sneaky', 'email' => "\"a\r\nb\"@example.com", 'password' => 'correct-horse-battery',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_a_soft_deleted_user_keeps_their_data_but_cannot_log_in(): void
    {
        $user = $this->userWithData();
        $user->update(['password' => Hash::make('secret-password-12')]);

        $this->actingAs($this->admin())->deleteJson("/api/admin/users/{$user->id}")->assertOk();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        // Everything they own survives, ready for a restore.
        $this->assertDatabaseHas('saved_requests', ['user_id' => $user->id]);
        $this->assertDatabaseHas('monitors', ['user_id' => $user->id]);

        // Soft-deleted users are excluded from every query, the auth provider
        // included, so deactivation locks the account out.
        $this->postJson('/api/login', [
            'email' => $user->email, 'password' => 'secret-password-12',
        ])->assertStatus(401);
    }

    public function test_a_restored_user_can_log_in_again(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret-password-12')]);
        $admin = $this->admin();

        $this->actingAs($admin)->deleteJson("/api/admin/users/{$user->id}")->assertOk();
        $this->actingAs($admin)->postJson("/api/admin/users/{$user->id}/restore")->assertOk();

        $this->assertNull($user->fresh()->deleted_at);
        $this->postJson('/api/login', [
            'email' => $user->email, 'password' => 'secret-password-12',
        ])->assertOk();
    }

    public function test_a_hard_delete_removes_the_user_and_every_associated_record(): void
    {
        $user = $this->userWithData();

        $response = $this->actingAs($this->admin())
            ->deleteJson("/api/admin/users/{$user->id}/force")
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);

        foreach (['saved_requests', 'request_histories', 'environments', 'collections',
            'monitors', 'alert_channels', 'inspection_reports'] as $table) {
            $this->assertDatabaseMissing($table, ['user_id' => $user->id]);
        }

        // Collection steps hang off the collection, not the user directly.
        $this->assertSame(0, \App\Models\CollectionStep::count());

        $response->assertJsonPath('deleted.saved_requests', 1)
            ->assertJsonPath('deleted.monitors', 1);
    }

    public function test_a_hard_delete_can_be_applied_to_an_already_soft_deleted_user(): void
    {
        $user = $this->userWithData();
        $admin = $this->admin();

        $this->actingAs($admin)->deleteJson("/api/admin/users/{$user->id}")->assertOk();
        $this->actingAs($admin)->deleteJson("/api/admin/users/{$user->id}/force")->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_the_audit_log_survives_deleting_the_user_it_describes(): void
    {
        $user = $this->userWithData();

        $this->actingAs($this->admin())->deleteJson("/api/admin/users/{$user->id}/force")->assertOk();

        // target_user_id is a snapshot, not a foreign key, so the record of
        // what happened outlives the account.
        $this->assertDatabaseHas('admin_actions', [
            'action' => 'force_delete_user',
            'target_email' => $user->email,
        ]);
    }

    public function test_the_audit_log_survives_deleting_the_admin_who_wrote_it(): void
    {
        $actor = $this->admin();
        $target = User::factory()->create();

        $this->actingAs($actor)->deleteJson("/api/admin/users/{$target->id}")->assertOk();

        // Hard-deleting that admin must not erase what they did.
        $this->actingAs($this->admin())->deleteJson("/api/admin/users/{$actor->id}/force")->assertOk();

        $entry = AdminAction::where('action', 'delete_user')->firstOrFail();
        $this->assertNull($entry->admin_id, 'The reference should null out, not cascade.');
        $this->assertSame($actor->email, $entry->admin_email, 'The email snapshot should identify who acted.');
    }

    public function test_an_admin_cannot_delete_themselves_either_way(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->deleteJson("/api/admin/users/{$admin->id}")->assertStatus(422);
        $this->actingAs($admin)->deleteJson("/api/admin/users/{$admin->id}/force")->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'deleted_at' => null]);
    }

    public function test_the_listing_can_filter_by_deleted_state(): void
    {
        $admin = $this->admin();
        $kept = User::factory()->create();
        $removed = User::factory()->create();

        $this->actingAs($admin)->deleteJson("/api/admin/users/{$removed->id}")->assertOk();

        // Default hides deleted accounts.
        $active = $this->actingAs($admin)->getJson('/api/admin/users')->assertOk()->json('data');
        $this->assertNotContains($removed->id, array_column($active, 'id'));
        $this->assertContains($kept->id, array_column($active, 'id'));

        $trashed = $this->actingAs($admin)->getJson('/api/admin/users?filter=trashed')->assertOk()->json('data');
        $this->assertSame([$removed->id], array_column($trashed, 'id'));

        $all = $this->actingAs($admin)->getJson('/api/admin/users?filter=all')->assertOk()->json('data');
        $this->assertCount(3, $all);
    }

    public function test_a_deleted_users_detail_page_still_opens(): void
    {
        $user = User::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)->deleteJson("/api/admin/users/{$user->id}")->assertOk();

        // An admin needs to see what they are about to restore or purge.
        $this->actingAs($admin)->getJson("/api/admin/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_user_lifecycle_actions_are_closed_to_non_admins(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $target = User::factory()->create();

        $this->actingAs($user)->postJson('/api/admin/users', [
            'name' => 'x', 'email' => 'x@example.com', 'password' => 'correct-horse-battery',
        ])->assertStatus(403);
        $this->actingAs($user)->deleteJson("/api/admin/users/{$target->id}/force")->assertStatus(403);
        $this->actingAs($user)->postJson("/api/admin/users/{$target->id}/restore")->assertStatus(403);
    }
}
