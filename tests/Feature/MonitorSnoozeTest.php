<?php

namespace Tests\Feature;

use App\Models\Monitor;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\MonitorStatusChanged;
use App\Services\Monitors\MonitorRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MonitorSnoozeTest extends TestCase
{
    use RefreshDatabase;

    /** Whether the fake upstream is currently healthy. */
    private bool $healthy = true;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        // One stub reading mutable state: calling Http::fake() again appends
        // to the stub list rather than replacing it.
        Http::fake(['api.example.com/*' => function () {
            return $this->healthy
                ? Http::response(['ok' => true], 200)
                : Http::response(['error' => 'down'], 500);
        }]);
    }

    private function monitor(User $user): Monitor
    {
        $saved = $user->savedRequests()->create([
            'name' => 'Health', 'protocol' => 'rest', 'method' => 'GET',
            'url' => 'https://api.example.com/health',
            'assertions' => [['path' => 'status', 'operator' => 'equals', 'expected' => '200']],
        ]);
        $collection = $user->collections()->create(['name' => 'Smoke']);
        $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => 0]);

        return $user->monitors()->create([
            'name' => 'Health check',
            'collection_id' => $collection->id,
            'interval_minutes' => 60,
        ]);
    }

    private function tick(Monitor $monitor)
    {
        return app(MonitorRunner::class)->run($monitor->fresh());
    }

    // --------------------------------------------------------------- the model

    public function test_a_monitor_is_snoozed_only_while_the_time_is_in_the_future(): void
    {
        $monitor = new Monitor(['snoozed_until' => now()->addHour()]);
        $this->assertTrue($monitor->isSnoozed());

        $monitor->snoozed_until = now()->subMinute();
        $this->assertFalse($monitor->isSnoozed());

        $monitor->snoozed_until = null;
        $this->assertFalse($monitor->isSnoozed());
    }

    // ------------------------------------------------------------- suppression

    public function test_a_snoozed_monitor_still_runs_and_records_its_result(): void
    {
        // The difference between muting a monitor and turning it off: the
        // history and the uptime figure stay honest.
        $user = User::factory()->create();
        $monitor = $this->monitor($user);
        $this->tick($monitor);

        $monitor->update(['snoozed_until' => now()->addHour()]);
        $this->healthy = false;
        $result = $this->tick($monitor);

        $this->assertFalse($result->passed);
        $this->assertSame(2, $monitor->fresh()->results()->count());
        $this->assertSame(Monitor::STATUS_FAILING, $monitor->fresh()->last_status);
    }

    public function test_no_alert_is_sent_while_snoozed(): void
    {
        $user = User::factory()->create();
        $monitor = $this->monitor($user);
        $this->tick($monitor);

        $monitor->update(['snoozed_until' => now()->addHour()]);
        $this->healthy = false;
        $this->tick($monitor);

        Notification::assertNothingSent();
        $this->assertFalse(
            UserNotification::where('type', 'monitor_failing')->exists(),
            'An in-app notification is still an alert.'
        );
    }

    public function test_a_failure_that_begins_during_a_snooze_alerts_once_it_ends(): void
    {
        // The whole point: delayed, not swallowed.
        $user = User::factory()->create();
        $monitor = $this->monitor($user);
        $this->tick($monitor);

        $monitor->update(['snoozed_until' => now()->addHour()]);
        $this->healthy = false;
        $this->tick($monitor);
        Notification::assertNothingSent();

        // Snooze expires, the target is still down.
        $monitor->update(['snoozed_until' => now()->subMinute()]);
        $this->tick($monitor);

        Notification::assertSentTo($user, MonitorStatusChanged::class);
        $this->assertTrue(UserNotification::where('type', 'monitor_failing')->exists());
    }

    public function test_a_failure_that_recovers_during_a_snooze_never_alerts(): void
    {
        // Nothing was announced, so there is nothing to announce a recovery
        // from — the deploy blipped and nobody needed to know.
        $user = User::factory()->create();
        $monitor = $this->monitor($user);
        $this->tick($monitor);

        $monitor->update(['snoozed_until' => now()->addHour()]);
        $this->healthy = false;
        $this->tick($monitor);
        $this->healthy = true;
        $this->tick($monitor);

        $monitor->update(['snoozed_until' => now()->subMinute()]);
        $this->tick($monitor);

        Notification::assertNothingSent();
        $this->assertSame(Monitor::STATUS_PASSING, $monitor->fresh()->last_status);
    }

    public function test_alerts_resume_normally_after_a_snooze(): void
    {
        $user = User::factory()->create();
        $monitor = $this->monitor($user);
        $this->tick($monitor);

        $monitor->update(['snoozed_until' => now()->subMinute()]);
        $this->healthy = false;
        $this->tick($monitor);

        Notification::assertSentTo($user, MonitorStatusChanged::class);
    }

    public function test_the_announced_status_is_not_advanced_while_snoozed(): void
    {
        $user = User::factory()->create();
        $monitor = $this->monitor($user);
        $this->tick($monitor);
        $this->assertSame(Monitor::STATUS_PASSING, $monitor->fresh()->last_alerted_status);

        $monitor->update(['snoozed_until' => now()->addHour()]);
        $this->healthy = false;
        $this->tick($monitor);

        $fresh = $monitor->fresh();
        $this->assertSame(Monitor::STATUS_FAILING, $fresh->last_status, 'What was observed.');
        $this->assertSame(Monitor::STATUS_PASSING, $fresh->last_alerted_status, 'What was announced.');
    }

    // ------------------------------------------------------------- the endpoint

    public function test_a_monitor_can_be_snoozed_and_woken(): void
    {
        $user = User::factory()->create();
        $monitor = $this->monitor($user);

        $this->actingAs($user)->postJson("/api/monitors/{$monitor->id}/snooze", ['minutes' => 120])
            ->assertOk()
            ->assertJsonPath('is_snoozed', true);

        $this->assertTrue($monitor->fresh()->isSnoozed());

        $this->actingAs($user)->deleteJson("/api/monitors/{$monitor->id}/snooze")
            ->assertOk()
            ->assertJsonPath('is_snoozed', false);

        $this->assertNull($monitor->fresh()->snoozed_until);
    }

    public function test_the_snooze_length_is_bounded(): void
    {
        $user = User::factory()->create();
        $monitor = $this->monitor($user);

        $this->actingAs($user)->postJson("/api/monitors/{$monitor->id}/snooze", ['minutes' => 0])
            ->assertStatus(422)->assertJsonValidationErrors('minutes');

        $this->actingAs($user)->postJson("/api/monitors/{$monitor->id}/snooze", [
            'minutes' => Monitor::MAX_SNOOZE_MINUTES + 1,
        ])->assertStatus(422)->assertJsonValidationErrors('minutes');
    }

    public function test_a_colleague_can_snooze_a_shared_monitor_but_a_stranger_cannot(): void
    {
        $owner = User::factory()->create();
        $colleague = User::factory()->create();
        $organisation = \App\Models\Organisation::create([
            'name' => 'Acme', 'slug' => 'acme', 'owner_user_id' => $owner->id,
        ]);
        $owner->update(['organisation_id' => $organisation->id]);
        $colleague->update(['organisation_id' => $organisation->id]);

        $monitor = $this->monitor($owner);

        $this->actingAs($colleague->fresh())
            ->postJson("/api/monitors/{$monitor->id}/snooze", ['minutes' => 60])
            ->assertOk();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/monitors/{$monitor->id}/snooze", ['minutes' => 60])
            ->assertNotFound();
    }

    public function test_snoozing_requires_authentication(): void
    {
        $user = User::factory()->create();
        $monitor = $this->monitor($user);

        $this->postJson("/api/monitors/{$monitor->id}/snooze", ['minutes' => 60])->assertUnauthorized();
    }

    public function test_the_monitor_list_reports_the_snooze(): void
    {
        $user = User::factory()->create();
        $monitor = $this->monitor($user);
        $monitor->update(['snoozed_until' => now()->addHour()]);

        $this->actingAs($user)->getJson('/api/monitors')
            ->assertOk()
            ->assertJsonPath('0.is_snoozed', true)
            ->assertJsonPath('0.snoozed_until', fn ($v) => $v !== null);
    }
}
