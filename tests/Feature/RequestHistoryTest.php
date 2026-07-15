<?php

namespace Tests\Feature;

use App\Models\RequestHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RequestHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_proxy_requests_by_authenticated_users_are_recorded(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/proxy', [
            'url' => 'https://api.example.com/data',
            'method' => 'GET',
            'headers' => ['Authorization' => 'Bearer secret'],
        ])->assertStatus(200);

        $this->assertDatabaseHas('request_histories', [
            'user_id' => $user->id,
            'protocol' => 'rest',
            'method' => 'GET',
            'status' => 200,
        ]);

        // Headers must never be persisted.
        $entry = RequestHistory::first();
        $this->assertNull($entry->params);
        $this->assertStringNotContainsString('secret', json_encode($entry->getAttributes()));
    }

    public function test_guest_proxy_requests_are_not_recorded(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);

        $this->postJson('/api/proxy', [
            'url' => 'https://api.example.com/data',
            'method' => 'GET',
        ])->assertStatus(200);

        $this->assertDatabaseCount('request_histories', 0);
    }

    public function test_mcp_requests_are_recorded_with_params(): void
    {
        Http::fake([
            'mcp.test/*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2025-06-18']], 200),
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/mcp/test', [
            'url' => 'https://mcp.test/mcp',
            'method' => 'initialize',
            'params' => ['probe' => true],
        ])->assertStatus(200);

        $entry = RequestHistory::first();
        $this->assertSame('mcp', $entry->protocol);
        $this->assertSame(['probe' => true], $entry->params);
    }

    public function test_failed_requests_are_recorded_with_null_status(): void
    {
        Http::fake([
            'agent.test/*' => Http::response('', 404),
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/a2a/test', [
            'url' => 'https://agent.test/a2a',
            'method' => 'agent-card',
        ])->assertStatus(500);

        $entry = RequestHistory::first();
        $this->assertSame('a2a', $entry->protocol);
        $this->assertNull($entry->status);
    }

    public function test_history_is_trimmed_to_retention_cap(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < RequestHistory::RETENTION_PER_USER + 5; $i++) {
            RequestHistory::record($user->id, [
                'protocol' => 'rest',
                'method' => 'GET',
                'url' => "https://api.example.com/{$i}",
                'status' => 200,
                'time_ms' => 10,
            ]);
        }

        $this->assertSame(RequestHistory::RETENTION_PER_USER, $user->requestHistories()->count());
        // Oldest entries were trimmed, newest kept.
        $this->assertSame(
            'https://api.example.com/'.(RequestHistory::RETENTION_PER_USER + 4),
            $user->requestHistories()->orderByDesc('id')->first()->url,
        );
    }

    public function test_history_endpoint_returns_own_entries_newest_first(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        RequestHistory::record($user->id, ['protocol' => 'rest', 'method' => 'GET', 'url' => 'https://one.test', 'status' => 200, 'time_ms' => 5]);
        RequestHistory::record($user->id, ['protocol' => 'mcp', 'method' => 'tools/list', 'url' => 'https://two.test', 'status' => 200, 'time_ms' => 5]);
        RequestHistory::record($other->id, ['protocol' => 'rest', 'method' => 'GET', 'url' => 'https://theirs.test', 'status' => 200, 'time_ms' => 5]);

        $response = $this->actingAs($user)->getJson('/api/history');

        $response->assertStatus(200)->assertJsonCount(2);
        $this->assertSame('https://two.test', $response->json('0.url'));
    }

    public function test_history_can_be_cleared(): void
    {
        $user = User::factory()->create();
        RequestHistory::record($user->id, ['protocol' => 'rest', 'method' => 'GET', 'url' => 'https://one.test', 'status' => 200, 'time_ms' => 5]);

        $this->actingAs($user)->deleteJson('/api/history')->assertStatus(200);

        $this->assertDatabaseCount('request_histories', 0);
    }

    public function test_history_endpoints_require_auth(): void
    {
        $this->getJson('/api/history')->assertStatus(401);
        $this->deleteJson('/api/history')->assertStatus(401);
    }
}
