<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RequestAuthTest extends TestCase
{
    use RefreshDatabase;

    /** Capture the outgoing request so we can assert what actually went out. */
    private function fake(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);
    }

    public function test_the_proxy_applies_bearer_auth_server_side(): void
    {
        $this->fake();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/proxy', [
            'method' => 'GET',
            'url' => 'https://api.example.com/users',
            'auth' => ['scheme' => 'bearer', 'token' => 'abc123'],
        ])->assertOk();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer abc123'));
    }

    public function test_the_proxy_applies_basic_auth(): void
    {
        $this->fake();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/proxy', [
            'method' => 'GET',
            'url' => 'https://api.example.com/users',
            'auth' => ['scheme' => 'basic', 'username' => 'ada', 'password' => 's3cret'],
        ])->assertOk();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Basic '.base64_encode('ada:s3cret')));
    }

    public function test_the_proxy_puts_an_api_key_in_the_query_string(): void
    {
        $this->fake();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/proxy', [
            'method' => 'GET',
            'url' => 'https://api.example.com/users?page=2',
            'auth' => ['scheme' => 'api_key', 'key' => 'api_key', 'value' => 'k-1', 'in' => 'query'],
        ])->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api_key=k-1') && str_contains($request->url(), 'page=2');
        });
    }

    public function test_an_unknown_scheme_is_rejected_by_validation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/proxy', [
            'method' => 'GET',
            'url' => 'https://api.example.com/users',
            'auth' => ['scheme' => 'hawk'],
        ])->assertStatus(422)->assertJsonValidationErrors('auth.scheme');
    }

    public function test_a_credential_can_live_in_a_secret_environment_variable(): void
    {
        // The whole point: the token is never typed into the request, and the
        // resolved value is masked in the echoed payload.
        $this->fake();
        $user = User::factory()->create();
        $environment = $user->environments()->create([
            'name' => 'Staging',
            'is_default' => true,
            'variables' => [['key' => 'token', 'value' => 'super-secret-value', 'secret' => true]],
        ]);

        $response = $this->actingAs($user)->postJson('/api/proxy', [
            'method' => 'GET',
            'url' => 'https://api.example.com/users',
            'environment_id' => $environment->id,
            'auth' => ['scheme' => 'bearer', 'token' => '{{token}}'],
        ])->assertOk();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer super-secret-value'));
        $this->assertStringNotContainsString('super-secret-value', $response->getContent());
    }

    public function test_a_collection_step_sends_its_saved_auth(): void
    {
        $this->fake();
        $user = User::factory()->create();

        $saved = $user->savedRequests()->create([
            'name' => 'List users',
            'protocol' => 'rest',
            'method' => 'GET',
            'url' => 'https://api.example.com/users',
            'auth' => ['scheme' => 'api_key', 'key' => 'X-Api-Key', 'value' => 'k-1', 'in' => 'header'],
        ]);
        $collection = $user->collections()->create(['name' => 'Smoke']);
        $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => 0]);

        $this->actingAs($user)->postJson("/api/collections/{$collection->id}/run")->assertOk();

        Http::assertSent(fn ($request) => $request->hasHeader('X-Api-Key', 'k-1'));
    }

    public function test_a_collection_step_resolves_a_variable_in_its_auth(): void
    {
        $this->fake();
        $user = User::factory()->create();
        $user->environments()->create([
            'name' => 'Staging',
            'is_default' => true,
            'variables' => [['key' => 'token', 'value' => 'run-token', 'secret' => true]],
        ]);

        $saved = $user->savedRequests()->create([
            'name' => 'List users',
            'protocol' => 'rest',
            'method' => 'GET',
            'url' => 'https://api.example.com/users',
            'auth' => ['scheme' => 'bearer', 'token' => '{{token}}'],
        ]);
        $collection = $user->collections()->create(['name' => 'Smoke']);
        $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => 0]);

        $this->actingAs($user)->postJson("/api/collections/{$collection->id}/run")->assertOk();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer run-token'));
    }

    public function test_auth_is_saved_on_a_request_and_carried_by_a_duplicate(): void
    {
        $user = User::factory()->create();
        $auth = ['scheme' => 'bearer', 'token' => '{{token}}'];

        $created = $this->actingAs($user)->postJson('/api/saved-requests', [
            'name' => 'List users',
            'method' => 'GET',
            'url' => 'https://api.example.com/users',
            'auth' => $auth,
        ])->assertCreated();

        $id = $created->json('id');
        $this->assertSame($auth, $user->savedRequests()->find($id)->auth);

        $copy = $this->actingAs($user)->postJson("/api/saved-requests/{$id}/duplicate")->assertCreated();
        $this->assertSame($auth, $user->savedRequests()->find($copy->json('id'))->auth);
    }

    public function test_the_mcp_tester_applies_auth_too(): void
    {
        // Otherwise an MCP server behind a token would authenticate inside a
        // collection run but not in the Tester — the same request, two results.
        Http::fake(['mcp.example.com/*' => Http::response([
            'jsonrpc' => '2.0', 'id' => 1,
            'result' => ['protocolVersion' => '2024-11-05', 'serverInfo' => ['name' => 'demo']],
        ], 200)]);

        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/mcp/test', [
            'url' => 'https://mcp.example.com/mcp',
            'method' => 'initialize',
            'auth' => ['scheme' => 'bearer', 'token' => 'mcp-token'],
        ]);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer mcp-token'));
    }

    public function test_requests_without_auth_behave_exactly_as_before(): void
    {
        $this->fake();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/proxy', [
            'method' => 'GET',
            'url' => 'https://api.example.com/users',
            'headers' => ['Accept' => 'application/json'],
        ])->assertOk();

        Http::assertSent(fn ($request) => ! $request->hasHeader('Authorization')
            && $request->hasHeader('Accept', 'application/json'));
    }
}
