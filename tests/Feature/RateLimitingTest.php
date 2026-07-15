<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_proxy_requests_are_limited_to_20_per_minute(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);

        $payload = ['url' => 'https://api.example.com/data', 'method' => 'GET'];

        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/proxy', $payload)->assertStatus(200);
        }

        $this->postJson('/api/proxy', $payload)->assertStatus(429);
    }

    public function test_authenticated_proxy_requests_get_a_higher_limit(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create();
        $payload = ['url' => 'https://api.example.com/data', 'method' => 'GET'];

        // Past the guest limit but well within the authenticated one.
        for ($i = 0; $i < 25; $i++) {
            $this->actingAs($user)->postJson('/api/proxy', $payload)->assertStatus(200);
        }
    }

    public function test_mcp_test_endpoint_is_throttled_per_user(): void
    {
        Http::fake([
            'mcp.test/*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2025-06-18']], 200),
        ]);

        $user = User::factory()->create();
        $payload = ['url' => 'https://mcp.test/mcp', 'method' => 'initialize'];

        for ($i = 0; $i < 60; $i++) {
            $this->actingAs($user)->postJson('/api/mcp/test', $payload)->assertStatus(200);
        }

        $this->actingAs($user)->postJson('/api/mcp/test', $payload)->assertStatus(429);
    }

    public function test_login_attempts_are_throttled(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/login', [
                'email' => 'nobody@example.com',
                'password' => 'wrong',
            ]);
        }

        $response = $this->postJson('/api/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong',
        ]);

        $response->assertStatus(429);
    }
}
