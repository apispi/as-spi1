<?php

namespace Tests\Feature;

use App\Models\UserNotification;
use App\Models\User;
use App\Services\Monitors\MonitorRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private int $targetStatus = 200;

    public function test_a_monitor_transition_creates_an_in_app_notification_without_alert_channels(): void
    {
        Notification::fake();
        Http::fake(fn () => Http::response([], $this->targetStatus));

        $user = User::factory()->create();
        $saved = $user->savedRequests()->create([
            'name' => 'Ping', 'protocol' => 'rest', 'method' => 'GET',
            'url' => 'https://api.example.com/ping',
            'assertions' => [['path' => 'status', 'operator' => 'equals', 'expected' => 200]],
        ]);
        $collection = $user->collections()->create(['name' => 'Smoke']);
        $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => 0]);
        // No alert channels, alerts disabled — in-app must still fire.
        $monitor = $user->monitors()->create([
            'collection_id' => $collection->id, 'name' => 'Prod', 'interval_minutes' => 60, 'alerts_enabled' => false,
        ]);

        app(MonitorRunner::class)->run($monitor);            // baseline: passing
        $this->assertSame(0, UserNotification::where('user_id', $user->id)->count());

        $this->targetStatus = 500;
        app(MonitorRunner::class)->run($monitor->fresh());   // passing -> failing

        $note = UserNotification::where('user_id', $user->id)->first();
        $this->assertNotNull($note);
        $this->assertSame('monitor_failing', $note->type);
        $this->assertStringContainsString('Prod', $note->title);

        $this->targetStatus = 200;
        app(MonitorRunner::class)->run($monitor->fresh());   // failing -> passing
        $this->assertSame('monitor_recovered', UserNotification::where('user_id', $user->id)->latest('id')->first()->type);
    }

    public function test_push_records_and_lists_notifications(): void
    {
        $user = User::factory()->create();
        UserNotification::record($user->id, 'monitor_failing', 'Monitor failing: A', '2/3 passed', '/reports');

        $res = $this->actingAs($user)->getJson('/api/notifications')->assertOk();
        $this->assertSame(1, $res->json('unread'));
        $this->assertSame('Monitor failing: A', $res->json('notifications.0.title'));
        $this->assertSame('/reports', $res->json('notifications.0.url'));
    }

    public function test_unread_count_and_mark_read(): void
    {
        $user = User::factory()->create();
        $a = UserNotification::record($user->id, 'monitor_failing', 'A');
        UserNotification::record($user->id, 'monitor_failing', 'B');

        $this->actingAs($user)->getJson('/api/notifications/unread-count')
            ->assertOk()->assertJsonPath('unread', 2);

        $this->actingAs($user)->postJson("/api/notifications/{$a->id}/read")->assertOk();
        $this->actingAs($user)->getJson('/api/notifications/unread-count')->assertJsonPath('unread', 1);

        $this->actingAs($user)->postJson('/api/notifications/read')->assertOk();
        $this->actingAs($user)->getJson('/api/notifications/unread-count')->assertJsonPath('unread', 0);
    }

    public function test_notifications_are_per_user(): void
    {
        $owner = User::factory()->create();
        UserNotification::record($owner->id, 'monitor_failing', 'Theirs');

        $this->actingAs(User::factory()->create())->getJson('/api/notifications')
            ->assertOk()->assertJsonPath('unread', 0)->assertJsonCount(0, 'notifications');
    }

    public function test_history_is_trimmed_per_user(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < UserNotification::KEEP_PER_USER + 5; $i++) {
            UserNotification::record($user->id, 'monitor_failing', "n{$i}");
        }

        $this->assertSame(UserNotification::KEEP_PER_USER, UserNotification::where('user_id', $user->id)->count());
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/notifications')->assertStatus(401);
    }
}
