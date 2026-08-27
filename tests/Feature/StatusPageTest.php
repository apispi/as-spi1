<?php

namespace Tests\Feature;

use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatusPageTest extends TestCase
{
    use RefreshDatabase;

    private function monitorFor(User $user, string $name, string $status = 'passing'): Monitor
    {
        $collection = $user->collections()->firstOrCreate(['name' => 'Smoke '.$name]);

        $monitor = $user->monitors()->create([
            'collection_id' => $collection->id, 'name' => $name,
            'interval_minutes' => 60, 'last_status' => $status,
        ]);

        $monitor->results()->create(['passed' => $status === 'passing', 'time_ms' => 120, 'passed_count' => 1, 'total' => 1]);

        return $monitor;
    }

    public function test_a_page_publishes_only_the_chosen_monitors(): void
    {
        $user = User::factory()->create();
        $published = $this->monitorFor($user, 'API checks');
        $this->monitorFor($user, 'Internal thing');

        $created = $this->actingAs($user)->postJson('/api/status-pages', [
            'name' => 'Acme API status',
            'monitor_ids' => [$published->id],
        ])->assertStatus(201);

        $token = StatusPage::first()->token;

        $this->getJson("/api/status/{$token}")
            ->assertOk()
            ->assertJsonPath('name', 'Acme API status')
            ->assertJsonPath('overall', 'passing')
            ->assertJsonCount(1, 'monitors')
            ->assertJsonPath('monitors.0.name', 'API checks')
            ->assertJsonPath('monitors.0.uptime', 100)
            ->assertJsonPath('monitors.0.history.0.ok', true);

        $this->assertStringContainsString('/status/', $created->json('url'));
    }

    public function test_the_public_payload_leaks_nothing_sensitive(): void
    {
        $user = User::factory()->create();
        $monitor = $this->monitorFor($user, 'Public checks');

        $this->actingAs($user)->postJson('/api/status-pages', [
            'name' => 'Status', 'monitor_ids' => [$monitor->id],
        ])->assertStatus(201);

        $body = $this->getJson('/api/status/'.StatusPage::first()->token)->assertOk()->getContent();

        // No owner identity, no target URLs, no step detail.
        $this->assertStringNotContainsString($user->email, $body);
        $this->assertStringNotContainsString('collection', strtolower($body));
        $this->assertStringNotContainsString('Smoke', $body);
    }

    public function test_a_failing_monitor_fails_the_overall_state(): void
    {
        $user = User::factory()->create();
        $ok = $this->monitorFor($user, 'A');
        $bad = $this->monitorFor($user, 'B', 'failing');

        $this->actingAs($user)->postJson('/api/status-pages', [
            'name' => 'Status', 'monitor_ids' => [$ok->id, $bad->id],
        ]);

        $this->getJson('/api/status/'.StatusPage::first()->token)
            ->assertOk()
            ->assertJsonPath('overall', 'failing');
    }

    public function test_a_drift_monitor_is_labelled_as_a_contract(): void
    {
        $user = User::factory()->create();
        $drift = $user->monitors()->create([
            'name' => 'Acme MCP contract', 'type' => Monitor::TYPE_MCP_DRIFT,
            'target_url' => 'https://mcp.acme.example.com/tools',
            'interval_minutes' => 60, 'last_status' => 'passing',
        ]);
        $drift->results()->create(['passed' => true, 'time_ms' => 0, 'passed_count' => 3, 'total' => 3]);

        $this->actingAs($user)->postJson('/api/status-pages', [
            'name' => 'MCP status', 'monitor_ids' => [$drift->id],
        ]);

        $this->getJson('/api/status/'.StatusPage::first()->token)
            ->assertOk()
            ->assertJsonPath('monitors.0.kind', 'mcp_contract');
    }

    public function test_disabling_a_page_takes_it_offline(): void
    {
        $user = User::factory()->create();
        $monitor = $this->monitorFor($user, 'A');

        $created = $this->actingAs($user)->postJson('/api/status-pages', [
            'name' => 'Status', 'monitor_ids' => [$monitor->id],
        ]);

        $token = StatusPage::first()->token;

        $this->actingAs($user)->putJson('/api/status-pages/'.$created->json('id'), [
            'name' => 'Status', 'is_enabled' => false,
        ])->assertOk();

        $this->getJson("/api/status/{$token}")->assertStatus(404);
    }

    public function test_another_users_monitors_cannot_be_published(): void
    {
        $other = User::factory()->create();
        $theirs = $this->monitorFor($other, 'Theirs');

        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/status-pages', [
            'name' => 'Sneaky', 'monitor_ids' => [$theirs->id],
        ])->assertStatus(201);

        $this->assertSame(0, StatusPage::first()->monitors()->count());
    }

    public function test_management_requires_authentication(): void
    {
        $this->getJson('/api/status-pages')->assertStatus(401);
        $this->getJson('/api/status/'.str_repeat('x', 40))->assertStatus(404);
    }
}
