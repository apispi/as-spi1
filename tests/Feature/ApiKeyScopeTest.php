<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiKeyScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);
    }

    private function proxy(string $token)
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/proxy', ['url' => 'https://api.example.com/x', 'method' => 'GET']);
    }

    private function runCollection(User $user, string $token)
    {
        $saved = $user->savedRequests()->create(['name' => 'S', 'protocol' => 'rest', 'method' => 'GET', 'url' => 'https://api.example.com/x']);
        $collection = $user->collections()->create(['name' => 'Suite']);
        $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => 0]);

        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/collections/{$collection->id}/run");
    }

    public function test_a_full_access_key_can_do_everything(): void
    {
        $user = User::factory()->create();
        [, $plain] = ApiKey::issue($user, 'Full'); // no scopes = full

        $this->proxy($plain)->assertOk();
        $this->runCollection($user, $plain)->assertOk();
    }

    public function test_a_requests_only_key_cannot_run_collections(): void
    {
        $user = User::factory()->create();
        [, $plain] = ApiKey::issue($user, 'CI requests', null, ['requests']);

        $this->proxy($plain)->assertOk();
        $this->runCollection($user, $plain)->assertStatus(403);
    }

    public function test_a_collections_only_key_cannot_send_requests(): void
    {
        $user = User::factory()->create();
        [, $plain] = ApiKey::issue($user, 'CI collections', null, ['collections']);

        $this->proxy($plain)->assertStatus(403);
        $this->runCollection($user, $plain)->assertOk();
    }

    public function test_a_legacy_token_is_unscoped(): void
    {
        $user = User::factory()->create();
        $legacy = $user->generateApiKey();

        $this->proxy($legacy)->assertOk();
        $this->runCollection($user, $legacy)->assertOk();
    }

    public function test_store_validates_scopes_and_persists_them(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/user/api-keys', ['name' => 'Scoped', 'scopes' => ['requests']])
            ->assertStatus(201)->assertJsonPath('scopes', ['requests']);

        $this->actingAs($user)->postJson('/api/user/api-keys', ['name' => 'Bad', 'scopes' => ['wizardry']])
            ->assertStatus(422)->assertJsonValidationErrors(['scopes.0']);
    }
}
