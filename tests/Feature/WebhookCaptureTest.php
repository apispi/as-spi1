<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Notifications\WebhookSilenceChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WebhookCaptureTest extends TestCase
{
    use RefreshDatabase;

    private function endpoint(User $user, array $attributes = []): WebhookEndpoint
    {
        return $user->webhookEndpoints()->create(array_merge([
            'name' => 'Payments callback',
            'token' => WebhookEndpoint::generateToken(),
        ], $attributes));
    }

    public function test_it_captures_whatever_arrives_without_authentication(): void
    {
        $endpoint = $this->endpoint(User::factory()->create());

        $this->postJson("/hook/{$endpoint->token}?source=stripe", ['event' => 'paid'], [
            'X-Signature' => 'sig123',
        ])->assertOk()->assertJsonPath('ok', true);

        $capture = $endpoint->captures()->first();
        $this->assertSame('POST', $capture->method);
        $this->assertSame('{"event":"paid"}', $capture->body);
        $this->assertSame(['source' => 'stripe'], $capture->query);
        $this->assertSame('sig123', $capture->headers['x-signature']);
        $this->assertSame(WebhookEndpoint::STATUS_RECEIVING, $endpoint->fresh()->last_status);
    }

    public function test_credential_headers_are_redacted_at_capture(): void
    {
        // A capture is third-party traffic; storing their Authorization header
        // verbatim would make Spi a credential store for other services.
        $endpoint = $this->endpoint(User::factory()->create());

        $this->postJson("/hook/{$endpoint->token}", [], ['Authorization' => 'Bearer their-secret'])
            ->assertOk();

        $headers = $endpoint->captures()->first()->headers;
        $this->assertSame('••••••', $headers['authorization']);
        $this->assertStringNotContainsString('their-secret', json_encode($headers));
    }

    public function test_an_unknown_token_is_a_404(): void
    {
        $this->postJson('/hook/'.str_repeat('x', 40), ['a' => 1])->assertStatus(404);
    }

    public function test_oversized_bodies_are_truncated_not_rejected(): void
    {
        $endpoint = $this->endpoint(User::factory()->create());

        $this->call('POST', "/hook/{$endpoint->token}", [], [], [], [], str_repeat('a', 70000))
            ->assertOk()
            ->assertJsonPath('truncated', true);

        $this->assertSame(65536, strlen($endpoint->captures()->first()->body));
    }

    public function test_the_silence_check_flags_an_overdue_endpoint_and_alerts_once(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $endpoint = $this->endpoint($user, ['expect_interval_minutes' => 30]);
        $endpoint->forceFill([
            'last_received_at' => now()->subMinutes(45),
            'last_status' => WebhookEndpoint::STATUS_RECEIVING,
        ])->save();

        $this->artisan('webhooks:check')->assertExitCode(0);

        $this->assertSame(WebhookEndpoint::STATUS_SILENT, $endpoint->fresh()->last_status);
        Notification::assertSentTo($user, WebhookSilenceChanged::class);

        // Still silent on the next tick: no second alert.
        $this->artisan('webhooks:check')->assertExitCode(0);
        Notification::assertSentToTimes($user, WebhookSilenceChanged::class, 1);
    }

    public function test_a_hit_on_a_silent_endpoint_sends_the_recovery_alert(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $endpoint = $this->endpoint($user, [
            'expect_interval_minutes' => 30,
            'last_status' => WebhookEndpoint::STATUS_SILENT,
        ]);

        $this->postJson("/hook/{$endpoint->token}", ['back' => true])->assertOk();

        $this->assertSame(WebhookEndpoint::STATUS_RECEIVING, $endpoint->fresh()->last_status);
        Notification::assertSentTo($user, WebhookSilenceChanged::class);
    }

    public function test_an_endpoint_that_never_received_anything_can_still_go_silent(): void
    {
        // The dead-man's switch must catch "the cron never fired at all", not
        // only "it fired once and stopped".
        Notification::fake();
        $user = User::factory()->create();
        $endpoint = $this->endpoint($user, ['expect_interval_minutes' => 30]);
        $endpoint->forceFill(['created_at' => now()->subHour()])->save();

        $this->artisan('webhooks:check')->assertExitCode(0);

        $this->assertSame(WebhookEndpoint::STATUS_SILENT, $endpoint->fresh()->last_status);
    }

    public function test_capture_only_endpoints_are_never_flagged(): void
    {
        Notification::fake();
        $endpoint = $this->endpoint(User::factory()->create());
        $endpoint->forceFill(['created_at' => now()->subWeek()])->save();

        $this->artisan('webhooks:check')->assertExitCode(0);

        $this->assertSame(WebhookEndpoint::STATUS_UNKNOWN, $endpoint->fresh()->last_status);
        Notification::assertNothingSent();
    }

    public function test_retention_trims_old_captures(): void
    {
        $endpoint = $this->endpoint(User::factory()->create());

        for ($i = 0; $i < WebhookEndpoint::RETENTION + 5; $i++) {
            $endpoint->captures()->create(['method' => 'POST']);
        }

        $this->postJson("/hook/{$endpoint->token}", ['final' => true])->assertOk();

        $this->assertSame(WebhookEndpoint::RETENTION, $endpoint->captures()->count());
    }

    public function test_owners_manage_endpoints_and_read_captures(): void
    {
        $user = User::factory()->create();

        $created = $this->actingAs($user)->postJson('/api/webhook-endpoints', [
            'name' => 'CI heartbeat', 'expect_interval_minutes' => 60,
        ])->assertStatus(201);

        $this->assertStringContainsString('/hook/', $created->json('url'));

        $token = WebhookEndpoint::first()->token;
        $this->postJson("/hook/{$token}", ['ping' => 1])->assertOk();

        $this->actingAs($user)
            ->getJson('/api/webhook-endpoints/'.$created->json('id').'/captures')
            ->assertOk()
            ->assertJsonCount(1, 'captures')
            ->assertJsonPath('endpoint.last_status', 'receiving');
    }

    public function test_a_user_cannot_read_another_users_captures(): void
    {
        $owner = User::factory()->create();
        $endpoint = $this->endpoint($owner);

        $this->actingAs(User::factory()->create())
            ->getJson("/api/webhook-endpoints/{$endpoint->id}/captures")
            ->assertStatus(404);
    }
}
