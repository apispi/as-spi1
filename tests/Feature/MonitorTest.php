<?php

namespace Tests\Feature;

use App\Models\Monitor;
use App\Models\User;
use App\Notifications\MonitorStatusChanged;
use App\Services\Monitors\MonitorRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MonitorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Status the faked endpoint returns. Http::fake() MERGES stubs rather than
     * replacing them, so calling it twice leaves the first stub winning; one
     * closure reading this property is how a test changes the response
     * mid-way.
     */
    private int $endpointStatus = 200;

    private function fakeEndpoint(int $status): void
    {
        $this->endpointStatus = $status;

        Http::fake(fn () => Http::response([], $this->endpointStatus));
    }

    private function monitorFor(User $user, array $attributes = []): Monitor
    {
        $saved = $user->savedRequests()->create([
            'name' => 'Ping', 'protocol' => 'rest', 'method' => 'GET',
            'url' => 'https://api.example.com/ping',
            'assertions' => [['path' => 'status', 'operator' => 'equals', 'expected' => 200]],
        ]);

        $collection = $user->collections()->create(['name' => 'Smoke '.($attributes['name'] ?? 'default')]);
        $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => 0]);

        return $user->monitors()->create(array_merge([
            'collection_id' => $collection->id,
            'name' => 'Prod smoke',
            'interval_minutes' => 60,
        ], $attributes));
    }

    public function test_a_run_records_history_and_sets_status(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create();
        $monitor = $this->monitorFor($user);

        app(MonitorRunner::class)->run($monitor);

        $monitor->refresh();
        $this->assertSame(Monitor::STATUS_PASSING, $monitor->last_status);
        $this->assertNotNull($monitor->last_run_at);
        $this->assertSame(1, $monitor->results()->count());
        $this->assertTrue($monitor->results()->first()->passed);
    }

    public function test_the_first_run_does_not_alert(): void
    {
        Notification::fake();
        Http::fake(['api.example.com/*' => Http::response([], 500)]);

        $user = User::factory()->create();
        $monitor = $this->monitorFor($user);

        app(MonitorRunner::class)->run($monitor);

        // The first run establishes a baseline rather than announcing an
        // outage that may have predated the monitor.
        Notification::assertNothingSent();
        $this->assertSame(Monitor::STATUS_FAILING, $monitor->fresh()->last_status);
    }

    public function test_it_alerts_on_transition_and_stays_quiet_while_still_failing(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $monitor = $this->monitorFor($user);

        $this->fakeEndpoint(200);
        app(MonitorRunner::class)->run($monitor);   // baseline: passing

        $this->endpointStatus = 500;
        app(MonitorRunner::class)->run($monitor->fresh());   // passing -> failing: alert

        Notification::assertSentTimes(MonitorStatusChanged::class, 1);

        app(MonitorRunner::class)->run($monitor->fresh());   // still failing: silence
        app(MonitorRunner::class)->run($monitor->fresh());

        Notification::assertSentTimes(MonitorStatusChanged::class, 1);
        $this->assertSame(3, $monitor->fresh()->consecutive_failures);
    }

    public function test_it_alerts_on_recovery(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $monitor = $this->monitorFor($user);

        $this->fakeEndpoint(500);
        app(MonitorRunner::class)->run($monitor);            // baseline: failing

        $this->endpointStatus = 200;
        app(MonitorRunner::class)->run($monitor->fresh());   // failing -> passing

        Notification::assertSentTimes(MonitorStatusChanged::class, 1);
        $this->assertSame(0, $monitor->fresh()->consecutive_failures);
    }

    public function test_alerts_can_be_turned_off(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $monitor = $this->monitorFor($user, ['alerts_enabled' => false]);

        $this->fakeEndpoint(200);
        app(MonitorRunner::class)->run($monitor);

        $this->endpointStatus = 500;
        app(MonitorRunner::class)->run($monitor->fresh());

        Notification::assertNothingSent();
    }

    public function test_dueness_respects_the_interval(): void
    {
        $user = User::factory()->create();
        $monitor = $this->monitorFor($user, ['interval_minutes' => 60]);

        $this->assertTrue($monitor->isDue(), 'A monitor that has never run is due.');

        $monitor->update(['last_run_at' => now()->subMinutes(30)]);
        $this->assertFalse($monitor->fresh()->isDue());

        $monitor->update(['last_run_at' => now()->subMinutes(61)]);
        $this->assertTrue($monitor->fresh()->isDue());

        $monitor->update(['is_enabled' => false, 'last_run_at' => null]);
        $this->assertFalse($monitor->fresh()->isDue(), 'A disabled monitor is never due.');
    }

    public function test_the_command_runs_only_due_monitors(): void
    {
        Http::fake(['api.example.com/*' => Http::response([], 200)]);

        $user = User::factory()->create();
        $due = $this->monitorFor($user, ['name' => 'Due']);
        $notDue = $this->monitorFor($user, ['name' => 'Not due', 'interval_minutes' => 60]);
        $notDue->update(['last_run_at' => now()->subMinutes(5)]);

        $this->artisan('monitors:run')->assertExitCode(0);

        $this->assertSame(1, $due->fresh()->results()->count());
        $this->assertSame(0, $notDue->fresh()->results()->count());
    }

    public function test_a_thrown_error_is_recorded_as_a_failing_result(): void
    {
        $user = User::factory()->create();
        $monitor = $this->monitorFor($user);
        // A collection with no steps makes the runner report nothing to run;
        // the monitor must still record a result rather than go silent.
        $monitor->collection->steps()->delete();

        app(MonitorRunner::class)->run($monitor);

        $this->assertSame(1, $monitor->results()->count());
        $this->assertFalse($monitor->results()->first()->passed);
    }

    public function test_uptime_is_computed_from_history(): void
    {
        $user = User::factory()->create();
        $monitor = $this->monitorFor($user);

        $this->assertNull($monitor->uptime(), 'Uptime is unknown before any run.');

        $monitor->results()->create(['passed' => true, 'time_ms' => 10, 'total' => 1, 'passed_count' => 1]);
        $monitor->results()->create(['passed' => true, 'time_ms' => 10, 'total' => 1, 'passed_count' => 1]);
        $monitor->results()->create(['passed' => false, 'time_ms' => 10, 'total' => 1, 'passed_count' => 0]);

        $this->assertSame(66.7, $monitor->fresh()->uptime());
    }

    public function test_it_creates_and_runs_a_monitor_over_the_api(): void
    {
        Http::fake(['api.example.com/*' => Http::response([], 200)]);

        $user = User::factory()->create();
        $monitor = $this->monitorFor($user);

        $this->actingAs($user)->postJson("/api/monitors/{$monitor->id}/run")
            ->assertOk()
            ->assertJsonPath('last_status', 'passing');

        $this->actingAs($user)->getJson("/api/monitors/{$monitor->id}")
            ->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('uptime', 100);
    }

    public function test_a_monitor_cannot_point_at_another_users_collection(): void
    {
        $other = User::factory()->create();
        $theirs = $other->collections()->create(['name' => 'Theirs']);

        $this->actingAs(User::factory()->create())->postJson('/api/monitors', [
            'name' => 'Sneaky',
            'collection_id' => $theirs->id,
            'interval_minutes' => 60,
        ])->assertStatus(422)->assertJsonValidationErrors(['collection_id']);
    }

    public function test_it_rejects_intervals_outside_the_allowed_set(): void
    {
        $user = User::factory()->create();
        $collection = $user->collections()->create(['name' => 'C']);

        $this->actingAs($user)->postJson('/api/monitors', [
            'name' => 'Too fast',
            'collection_id' => $collection->id,
            'interval_minutes' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors(['interval_minutes']);
    }

    public function test_monitors_require_authentication(): void
    {
        $this->getJson('/api/monitors')->assertStatus(401);
    }
}
