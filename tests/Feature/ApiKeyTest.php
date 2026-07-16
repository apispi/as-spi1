<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_key_details_report_no_key_initially(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/user/api-key')
            ->assertStatus(200)
            ->assertJsonPath('has_key', false)
            ->assertJsonPath('masked', null);
    }

    public function test_generating_a_key_returns_plaintext_once_and_stores_only_a_hash(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/user/api-key/regenerate');

        $response->assertStatus(201);
        $plain = $response->json('api_key');
        $this->assertStringStartsWith('spi_', $plain);

        // The plaintext must never be persisted.
        $stored = $user->fresh()->api_token;
        $this->assertNotSame($plain, $stored);
        $this->assertSame(hash('sha256', $plain), $stored);

        // Subsequent reads only expose a masked hint, never the key.
        $details = $this->actingAs($user)->getJson('/api/user/api-key');
        $details->assertJsonPath('has_key', true);
        $this->assertStringNotContainsString($plain, $details->getContent());
        $this->assertStringEndsWith(substr($plain, -4), $details->json('masked'));
    }

    public function test_regenerating_invalidates_the_previous_key(): void
    {
        $user = User::factory()->create();

        $first = $this->actingAs($user)->postJson('/api/user/api-key/regenerate')->json('api_key');
        $second = $this->actingAs($user)->postJson('/api/user/api-key/regenerate')->json('api_key');

        $this->assertNotSame($first, $second);
        $this->assertNull(User::findByApiKey($first));
        $this->assertNotNull(User::findByApiKey($second));
    }

    public function test_api_key_is_never_serialised_on_the_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/user/api-key/regenerate');

        $response = $this->actingAs($user)->getJson('/api/user');

        $response->assertJsonMissingPath('api_token');
    }

    public function test_v1_routes_reject_missing_and_invalid_keys(): void
    {
        $payload = ['url' => 'https://api.example.com/x', 'method' => 'GET'];

        $this->postJson('/api/v1/proxy', $payload)->assertStatus(401);

        $this->withHeader('Authorization', 'Bearer spi_totally-made-up')
            ->postJson('/api/v1/proxy', $payload)
            ->assertStatus(401);
    }

    public function test_v1_proxy_works_with_a_valid_key(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create();
        $key = $user->generateApiKey();

        $response = $this->withHeader('Authorization', 'Bearer '.$key)
            ->postJson('/api/v1/proxy', [
                'url' => 'https://api.example.com/data',
                'method' => 'GET',
            ]);

        $response->assertStatus(200)->assertJsonPath('status', 200);
    }

    public function test_v1_requests_are_attributed_to_the_key_owner_in_history(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create();
        $key = $user->generateApiKey();

        $this->withHeader('Authorization', 'Bearer '.$key)
            ->postJson('/api/v1/proxy', ['url' => 'https://api.example.com/data', 'method' => 'GET'])
            ->assertStatus(200);

        $this->assertDatabaseHas('request_histories', [
            'user_id' => $user->id,
            'protocol' => 'rest',
        ]);
    }

    public function test_v1_mcp_endpoint_works_with_a_key(): void
    {
        Http::fake([
            'mcp.test/*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2025-06-18']], 200),
        ]);

        $user = User::factory()->create();
        $key = $user->generateApiKey();

        $this->withHeader('Authorization', 'Bearer '.$key)
            ->postJson('/api/v1/mcp/test', ['url' => 'https://mcp.test/mcp', 'method' => 'initialize'])
            ->assertStatus(200);
    }
}
