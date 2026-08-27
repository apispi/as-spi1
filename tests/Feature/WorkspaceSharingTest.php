<?php

namespace Tests\Feature;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Full shared workspace: everyone in an organisation sees and uses one pool of
 * resources; users with no organisation are a workspace of one.
 */
class WorkspaceSharingTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;
    private User $bob;
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $org = Organisation::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->alice = User::factory()->create(['organisation_id' => $org->id]);
        $this->bob = User::factory()->create(['organisation_id' => $org->id]);
        // Different org — must be fully isolated.
        $other = Organisation::create(['name' => 'Globex', 'slug' => 'globex']);
        $this->outsider = User::factory()->create(['organisation_id' => $other->id]);
    }

    public function test_a_colleague_sees_and_can_edit_a_shared_environment(): void
    {
        $env = $this->alice->environments()->create(['name' => 'Staging', 'variables' => []]);

        // Bob (same org) sees it, with Alice marked as owner.
        $this->actingAs($this->bob)->getJson('/api/environments')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Staging')
            ->assertJsonPath('0.owner.id', $this->alice->id);

        // Bob can edit it.
        $this->actingAs($this->bob)->putJson("/api/environments/{$env->id}", [
            'name' => 'Staging', 'variables' => [['key' => 'host', 'value' => 'x', 'secret' => false]],
        ])->assertOk();

        // The outsider sees nothing and cannot touch it.
        $this->actingAs($this->outsider)->getJson('/api/environments')->assertOk()->assertJsonCount(0);
        $this->actingAs($this->outsider)->putJson("/api/environments/{$env->id}", [
            'name' => 'Hijack', 'variables' => [],
        ])->assertStatus(404);
    }

    public function test_a_solo_user_is_unaffected(): void
    {
        $solo = User::factory()->create(); // no organisation
        $stranger = User::factory()->create();

        $solo->environments()->create(['name' => 'Mine', 'variables' => []]);

        $this->actingAs($solo)->getJson('/api/environments')->assertOk()->assertJsonCount(1);
        // Another solo user shares nothing.
        $this->actingAs($stranger)->getJson('/api/environments')->assertOk()->assertJsonCount(0);
    }

    public function test_a_monitor_may_reference_a_colleagues_collection_and_environment(): void
    {
        $collection = $this->alice->collections()->create(['name' => 'Alice suite']);
        $env = $this->alice->environments()->create(['name' => 'Prod', 'variables' => []]);

        // Bob builds a monitor over Alice's collection — allowed in the shared
        // workspace.
        $this->actingAs($this->bob)->postJson('/api/monitors', [
            'name' => 'Bob watches Alice suite',
            'collection_id' => $collection->id,
            'environment_id' => $env->id,
            'interval_minutes' => 60,
        ])->assertStatus(201);

        // The outsider cannot.
        $this->actingAs($this->outsider)->postJson('/api/monitors', [
            'name' => 'Nope', 'collection_id' => $collection->id, 'interval_minutes' => 60,
        ])->assertStatus(422)->assertJsonValidationErrors(['collection_id']);
    }

    public function test_collection_steps_may_use_a_colleagues_saved_requests(): void
    {
        $saved = $this->alice->savedRequests()->create([
            'name' => 'Alice ping', 'protocol' => 'rest', 'method' => 'GET',
            'url' => 'https://api.example.com/ping',
        ]);

        $this->actingAs($this->bob)->postJson('/api/collections', [
            'name' => 'Bob suite',
            'steps' => [['saved_request_id' => $saved->id]],
        ])->assertStatus(201)->assertJsonCount(1, 'steps');

        // The outsider's step referencing Alice's request is silently dropped.
        $this->actingAs($this->outsider)->postJson('/api/collections', [
            'name' => 'Outsider suite',
            'steps' => [['saved_request_id' => $saved->id]],
        ])->assertStatus(201)->assertJsonCount(0, 'steps');
    }

    public function test_a_shared_collection_runs_and_the_report_is_visible_workspace_wide(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);

        $saved = $this->alice->savedRequests()->create([
            'name' => 'Ping', 'protocol' => 'rest', 'method' => 'GET',
            'url' => 'https://api.example.com/ping',
        ]);
        $collection = $this->alice->collections()->create(['name' => 'Smoke']);
        $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => 0]);

        // Bob runs Alice's collection.
        $this->actingAs($this->bob)->postJson("/api/collections/{$collection->id}/run")
            ->assertOk()->assertJsonPath('passed', true);

        // The run's report is visible to Alice too — one shared pool.
        $this->actingAs($this->alice)->getJson('/api/reports')
            ->assertOk()
            ->assertJsonPath('reports.0.type', 'collection_run');
    }

    public function test_environment_names_are_unique_across_the_workspace(): void
    {
        $this->alice->environments()->create(['name' => 'Staging', 'variables' => []]);

        // Bob cannot make a second "Staging" — they share the picker namespace.
        $this->actingAs($this->bob)->postJson('/api/environments', [
            'name' => 'Staging', 'variables' => [],
        ])->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_the_mcp_gateway_runs_a_colleagues_collection(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);

        $saved = $this->alice->savedRequests()->create([
            'name' => 'Ping', 'protocol' => 'rest', 'method' => 'GET',
            'url' => 'https://api.example.com/ping',
        ]);
        $collection = $this->alice->collections()->create(['name' => 'Shared suite']);
        $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => 0]);

        // Bob's API key drives the gateway against Alice's collection.
        $key = $this->bob->generateApiKey();
        $response = $this->withHeader('Authorization', 'Bearer '.$key)
            ->postJson('/api/gateway/tools', [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => 'run_collection', 'arguments' => ['collection' => 'Shared suite']],
            ])->assertOk();

        $data = json_decode($response->json('result.content.0.text'), true);
        $this->assertTrue($data['passed']);
    }
}
