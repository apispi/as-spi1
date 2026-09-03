<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiKeyManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_key_returns_the_plaintext_once(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/user/api-keys', ['name' => 'CI'])
            ->assertStatus(201)
            ->assertJsonPath('name', 'CI');

        $plain = $response->json('plaintext');
        $this->assertStringStartsWith('spi_', $plain);

        // Stored only as a hash; the listing never returns the plaintext.
        $this->assertDatabaseHas('api_keys', ['name' => 'CI', 'token_hash' => ApiKey::hash($plain)]);
        $list = $this->actingAs($user)->getJson('/api/user/api-keys')->json();
        $this->assertArrayNotHasKey('plaintext', $list[0]);
        $this->assertStringNotContainsString($plain, json_encode($list));
    }

    public function test_a_named_key_authenticates_the_v1_api_and_records_use(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create();
        [$key, $plain] = ApiKey::issue($user, 'Agent');

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/v1/proxy', ['url' => 'https://api.example.com/x', 'method' => 'GET'])
            ->assertOk();

        $this->assertNotNull($key->fresh()->last_used_at);
    }

    public function test_a_revoked_key_is_rejected(): void
    {
        $user = User::factory()->create();
        [$key, $plain] = ApiKey::issue($user, 'Old');

        $this->actingAs($user)->deleteJson("/api/user/api-keys/{$key->id}")->assertOk();
        $this->assertNotNull($key->fresh()->revoked_at);

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/v1/proxy', ['url' => 'https://api.example.com/x', 'method' => 'GET'])
            ->assertStatus(401);
    }

    public function test_an_expired_key_is_rejected(): void
    {
        $user = User::factory()->create();
        [$key, $plain] = ApiKey::issue($user, 'Temp', new \DateTimeImmutable('-1 hour'));

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/v1/proxy', ['url' => 'https://api.example.com/x', 'method' => 'GET'])
            ->assertStatus(401);
    }

    public function test_multiple_keys_coexist_and_revoking_one_leaves_the_others(): void
    {
        Http::fake(['api.example.com/*' => Http::response([], 200)]);

        $user = User::factory()->create();
        [$a, $planA] = ApiKey::issue($user, 'A');
        [$b, $planB] = ApiKey::issue($user, 'B');

        $this->actingAs($user)->deleteJson("/api/user/api-keys/{$a->id}")->assertOk();

        // A is dead, B still works.
        $this->withHeader('Authorization', 'Bearer '.$planA)
            ->postJson('/api/v1/proxy', ['url' => 'https://api.example.com/x', 'method' => 'GET'])->assertStatus(401);
        $this->withHeader('Authorization', 'Bearer '.$planB)
            ->postJson('/api/v1/proxy', ['url' => 'https://api.example.com/x', 'method' => 'GET'])->assertOk();
    }

    public function test_the_active_key_limit_is_enforced(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < ApiKey::MAX_PER_USER; $i++) {
            ApiKey::issue($user, "k{$i}");
        }

        $this->actingAs($user)->postJson('/api/user/api-keys', ['name' => 'one more'])->assertStatus(422);
    }

    public function test_the_legacy_single_key_still_authenticates(): void
    {
        Http::fake(['api.example.com/*' => Http::response([], 200)]);

        $user = User::factory()->create();
        $legacy = $user->generateApiKey(); // writes the old api_token column

        $this->withHeader('Authorization', 'Bearer '.$legacy)
            ->postJson('/api/v1/proxy', ['url' => 'https://api.example.com/x', 'method' => 'GET'])->assertOk();
    }

    public function test_a_user_cannot_revoke_another_users_key(): void
    {
        $owner = User::factory()->create();
        [$key] = ApiKey::issue($owner, 'Theirs');

        $this->actingAs(User::factory()->create())->deleteJson("/api/user/api-keys/{$key->id}")->assertStatus(404);
    }

    public function test_key_management_requires_authentication(): void
    {
        $this->getJson('/api/user/api-keys')->assertStatus(401);
    }
}
