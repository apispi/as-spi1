<?php

namespace Tests\Feature;

use App\Models\AlertChannel;
use App\Models\User;
use App\Services\Monitors\MonitorRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AlertChannelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Http::fake() MERGES stubs rather than replacing them, so calling it
     * twice leaves the first stub winning. One closure reading these
     * properties is how a test changes a response mid-run.
     */
    private int $targetStatus = 200;

    private int $hookStatus = 200;

    private function fakeHttp(): void
    {
        Http::fake(fn ($request) => str_contains($request->url(), 'api.example.com')
            ? Http::response([], $this->targetStatus)
            : Http::response(['ok' => true], $this->hookStatus));
    }

    private function channel(User $user, string $type = 'slack', array $attributes = []): AlertChannel
    {
        return $user->alertChannels()->create(array_merge([
            'name' => ucfirst($type),
            'type' => $type,
            'url' => 'https://hooks.example.com/services/T000/B000/abcd',
        ], $attributes));
    }

    private function monitorFor(User $user, array $channels = []): \App\Models\Monitor
    {
        $saved = $user->savedRequests()->create([
            'name' => 'Ping', 'protocol' => 'rest', 'method' => 'GET',
            'url' => 'https://api.example.com/ping',
            'assertions' => [['path' => 'status', 'operator' => 'equals', 'expected' => 200]],
        ]);
        $collection = $user->collections()->create(['name' => 'Smoke']);
        $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => 0]);

        $monitor = $user->monitors()->create([
            'collection_id' => $collection->id, 'name' => 'Prod', 'interval_minutes' => 60,
        ]);

        if ($channels) {
            $monitor->alertChannels()->sync(collect($channels)->pluck('id'));
        }

        return $monitor;
    }

    public function test_a_transition_posts_to_the_channel_in_its_own_format(): void
    {
        Notification::fake();
        $this->fakeHttp();

        $user = User::factory()->create();
        $monitor = $this->monitorFor($user, [$this->channel($user, 'slack')]);

        app(MonitorRunner::class)->run($monitor);          // baseline: passing
        $this->targetStatus = 500;

        app(MonitorRunner::class)->run($monitor->fresh()); // passing -> failing

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'hooks.example.com')) {
                return false;
            }

            // Slack incoming webhooks take a single `text` field.
            return isset($request->data()['text'])
                && str_contains($request->data()['text'], 'Prod')
                && str_contains($request->data()['text'], 'failing');
        });
    }

    public function test_a_generic_webhook_receives_a_structured_event(): void
    {
        Notification::fake();
        $this->fakeHttp();
        $this->targetStatus = 200;

        $user = User::factory()->create();
        $channel = $this->channel($user, 'webhook', ['url' => 'https://ops.example.com/hooks/spi']);
        $monitor = $this->monitorFor($user, [$channel]);

        app(MonitorRunner::class)->run($monitor);

        $this->targetStatus = 500;

        app(MonitorRunner::class)->run($monitor->fresh());

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'ops.example.com')) {
                return false;
            }

            $data = $request->data();

            return ($data['event'] ?? null) === 'monitor.status_changed'
                && ($data['status'] ?? null) === 'failing'
                && ($data['monitor']['name'] ?? null) === 'Prod';
        });
    }

    public function test_alerts_fire_only_on_a_transition(): void
    {
        Notification::fake();
        $this->fakeHttp();
        $this->targetStatus = 500;

        $user = User::factory()->create();
        $monitor = $this->monitorFor($user, [$this->channel($user)]);

        // First run is a baseline; the two after it are still failing.
        app(MonitorRunner::class)->run($monitor);
        app(MonitorRunner::class)->run($monitor->fresh());
        app(MonitorRunner::class)->run($monitor->fresh());

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'hooks.example.com'));
    }

    public function test_a_failing_channel_records_its_error_without_failing_the_run(): void
    {
        Notification::fake();
        $this->fakeHttp();
        $this->targetStatus = 200;
        $this->hookStatus = 500;

        $user = User::factory()->create();
        $channel = $this->channel($user);
        $monitor = $this->monitorFor($user, [$channel]);

        app(MonitorRunner::class)->run($monitor);

        $this->targetStatus = 500;
        $this->hookStatus = 500;

        $result = app(MonitorRunner::class)->run($monitor->fresh());

        // The run is recorded regardless of the alert failing.
        $this->assertFalse($result->passed);
        $this->assertSame(2, $monitor->results()->count());
        $this->assertStringContainsString('500', $channel->fresh()->last_error);
    }

    public function test_disabled_channels_are_skipped(): void
    {
        Notification::fake();
        $this->fakeHttp();
        $this->targetStatus = 200;

        $user = User::factory()->create();
        $monitor = $this->monitorFor($user, [$this->channel($user, 'slack', ['is_enabled' => false])]);

        app(MonitorRunner::class)->run($monitor);

        $this->targetStatus = 500;

        app(MonitorRunner::class)->run($monitor->fresh());

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'hooks.example.com'));
    }

    public function test_the_url_is_ssrf_checked_on_write(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/api/alert-channels', [
            'name' => 'Internal', 'type' => 'webhook', 'url' => 'http://169.254.169.254/latest/',
        ])->assertStatus(422)->assertJsonValidationErrors(['url']);
    }

    public function test_the_url_is_never_returned_to_the_browser(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/alert-channels', [
            'name' => 'Slack', 'type' => 'slack',
            'url' => 'https://hooks.example.com/services/T000/B000/supersecret',
        ])->assertStatus(201);

        // A Slack webhook URL is a credential: anyone holding it can post.
        $this->assertStringNotContainsString('supersecret', $response->getContent());
        $response->assertJsonPath('url_preview', 'hooks.example.com/…cret');
    }

    public function test_updating_without_resending_the_url_keeps_it(): void
    {
        $user = User::factory()->create();
        $channel = $this->channel($user);

        $this->actingAs($user)->putJson("/api/alert-channels/{$channel->id}", [
            'name' => 'Renamed', 'type' => 'slack',
        ])->assertOk();

        $this->assertSame('https://hooks.example.com/services/T000/B000/abcd', $channel->fresh()->url);
        $this->assertSame('Renamed', $channel->fresh()->name);
    }

    public function test_a_test_alert_can_be_sent_and_reports_failure(): void
    {
        $user = User::factory()->create();
        $channel = $this->channel($user);

        $this->fakeHttp();

        $this->actingAs($user)->postJson("/api/alert-channels/{$channel->id}/test")
            ->assertOk()->assertJsonPath('delivered', true);

        $this->hookStatus = 500;

        $this->actingAs($user)->postJson("/api/alert-channels/{$channel->id}/test")
            ->assertStatus(422)->assertJsonPath('delivered', false);
    }

    public function test_a_monitor_cannot_attach_another_users_channel(): void
    {
        $other = User::factory()->create();
        $theirs = $this->channel($other);

        $user = User::factory()->create();
        $monitor = $this->monitorFor($user);

        $this->actingAs($user)->putJson("/api/monitors/{$monitor->id}", [
            'name' => 'Prod', 'collection_id' => $monitor->collection_id,
            'interval_minutes' => 60, 'alert_channel_ids' => [$theirs->id],
        ])->assertOk();

        $this->assertSame(0, $monitor->alertChannels()->count());
    }

    public function test_alert_channels_require_authentication(): void
    {
        $this->getJson('/api/alert-channels')->assertStatus(401);
    }
}
