<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\OAuth2TokenProvider;
use App\Services\Auth\RequestAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A feature test rather than a unit one: the provider leans on the HTTP client,
 * the cache, and the SSRF guard, and it is their combination that matters.
 */
class OAuth2ClientCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_URL = 'https://auth.example.com/oauth/token';

    private function config(array $overrides = []): array
    {
        return array_merge([
            'scheme' => 'oauth2_client_credentials',
            'token_url' => self::TOKEN_URL,
            'client_id' => 'client-1',
            'client_secret' => 's3cret',
        ], $overrides);
    }

    private function fakeToken(string $token = 'tok-abc', int $expiresIn = 3600): void
    {
        Http::fake([
            'auth.example.com/*' => Http::response([
                'access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => $expiresIn,
            ], 200),
            'api.example.com/*' => Http::response(['ok' => true], 200),
        ]);
    }

    public function test_it_fetches_a_token_and_sends_it_as_a_bearer(): void
    {
        $this->fakeToken();

        $result = (new RequestAuthenticator)->apply($this->config(), [], 'https://api.example.com/users');

        $this->assertNull($result['error']);
        $this->assertSame('Bearer tok-abc', $result['headers']['Authorization']);

        Http::assertSent(function ($request) {
            return $request->url() === self::TOKEN_URL
                && $request['grant_type'] === 'client_credentials'
                // RFC 6749's preferred placement: credentials as HTTP Basic.
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('client-1:s3cret'));
        });
    }

    public function test_credentials_can_go_in_the_body_instead(): void
    {
        $this->fakeToken();

        (new RequestAuthenticator)->apply(
            $this->config(['credentials_in' => 'body']),
            [], 'https://api.example.com/users'
        );

        Http::assertSent(fn ($request) => $request['client_id'] === 'client-1'
            && $request['client_secret'] === 's3cret'
            && ! $request->hasHeader('Authorization'));
    }

    public function test_scope_and_audience_are_forwarded_when_set(): void
    {
        $this->fakeToken();

        (new RequestAuthenticator)->apply(
            $this->config(['scope' => 'read:users', 'audience' => 'https://api.example.com']),
            [], 'https://api.example.com/users'
        );

        Http::assertSent(fn ($request) => $request['scope'] === 'read:users'
            && $request['audience'] === 'https://api.example.com');
    }

    public function test_an_empty_scope_is_omitted_rather_than_sent_blank(): void
    {
        $this->fakeToken();

        (new RequestAuthenticator)->apply($this->config(['scope' => '']), [], 'https://api.example.com/users');

        Http::assertSent(fn ($request) => ! array_key_exists('scope', (array) $request->data()));
    }

    public function test_the_token_is_cached_so_a_run_costs_one_token_request(): void
    {
        $this->fakeToken();
        $auth = new RequestAuthenticator;

        for ($i = 0; $i < 3; $i++) {
            $auth->apply($this->config(), [], 'https://api.example.com/users');
        }

        Http::assertSentCount(1);
    }

    public function test_a_different_scope_is_a_different_cached_token(): void
    {
        $this->fakeToken();
        $auth = new RequestAuthenticator;

        $auth->apply($this->config(['scope' => 'read']), [], 'https://api.example.com/users');
        $auth->apply($this->config(['scope' => 'write']), [], 'https://api.example.com/users');

        Http::assertSentCount(2);
    }

    public function test_a_token_already_inside_the_expiry_margin_is_not_cached(): void
    {
        // Caching a token with seconds left would hand the next step a
        // credential that expires mid-flight.
        $this->fakeToken('tok-brief', 10);
        $auth = new RequestAuthenticator;

        $auth->apply($this->config(), [], 'https://api.example.com/users');
        $auth->apply($this->config(), [], 'https://api.example.com/users');

        Http::assertSentCount(2);
    }

    public function test_a_rejected_token_request_surfaces_the_oauth_error(): void
    {
        Http::fake(['auth.example.com/*' => Http::response([
            'error' => 'invalid_client', 'error_description' => 'Client authentication failed',
        ], 401)]);

        $result = (new RequestAuthenticator)->apply($this->config(), [], 'https://api.example.com/users');

        $this->assertStringContainsString('401', $result['error']);
        $this->assertStringContainsString('invalid_client', $result['error']);
        $this->assertStringContainsString('Client authentication failed', $result['error']);
        $this->assertArrayNotHasKey('Authorization', $result['headers']);
    }

    public function test_a_response_without_an_access_token_is_an_error(): void
    {
        Http::fake(['auth.example.com/*' => Http::response(['token_type' => 'Bearer'], 200)]);

        $result = (new RequestAuthenticator)->apply($this->config(), [], 'https://api.example.com/users');

        $this->assertSame('Token endpoint returned no access_token.', $result['error']);
    }

    public function test_an_incomplete_config_is_reported_before_any_request(): void
    {
        Http::fake();

        $result = (new RequestAuthenticator)->apply($this->config(['client_id' => '']), [], 'https://api.example.com/users');

        $this->assertStringContainsString('token URL and a client ID', $result['error']);
        Http::assertNothingSent();
    }

    public function test_the_token_endpoint_gets_the_same_ssrf_treatment_as_the_target(): void
    {
        Http::fake();

        $result = (new RequestAuthenticator)->apply(
            $this->config(['token_url' => 'http://127.0.0.1/oauth/token']),
            [], 'https://api.example.com/users'
        );

        $this->assertStringContainsString('Token URL rejected', $result['error']);
        Http::assertNothingSent();
    }

    public function test_the_proxy_refuses_the_request_when_the_token_cannot_be_obtained(): void
    {
        // The important part: it does NOT fall back to sending the request
        // unauthenticated and reporting whatever the target says to a stranger.
        Http::fake([
            'auth.example.com/*' => Http::response(['error' => 'invalid_client'], 401),
            'api.example.com/*' => Http::response(['ok' => true], 200),
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/proxy', [
            'method' => 'GET',
            'url' => 'https://api.example.com/users',
            'auth' => $this->config(),
        ])->assertStatus(422)
            ->assertJsonPath('error', fn ($e) => str_contains($e, 'invalid_client'));

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.example.com'));
    }

    public function test_the_proxy_sends_the_fetched_token(): void
    {
        $this->fakeToken();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/proxy', [
            'method' => 'GET',
            'url' => 'https://api.example.com/users',
            'auth' => $this->config(),
        ])->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.example.com')
            && $request->hasHeader('Authorization', 'Bearer tok-abc'));
    }

    public function test_a_collection_step_fails_rather_than_running_unauthenticated(): void
    {
        Http::fake([
            'auth.example.com/*' => Http::response(['error' => 'invalid_client'], 401),
            'api.example.com/*' => Http::response(['ok' => true], 200),
        ]);

        $user = User::factory()->create();
        $saved = $user->savedRequests()->create([
            'name' => 'List users', 'protocol' => 'rest', 'method' => 'GET',
            'url' => 'https://api.example.com/users',
            'auth' => $this->config(),
        ]);
        $collection = $user->collections()->create(['name' => 'Smoke']);
        $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => 0]);

        $this->actingAs($user)->postJson("/api/collections/{$collection->id}/run")
            ->assertStatus(422)
            ->assertJsonPath('passed', false)
            ->assertJsonPath('steps.0.error', fn ($e) => str_contains($e, 'invalid_client'));

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.example.com'));
    }

    public function test_a_client_secret_can_come_from_a_secret_variable(): void
    {
        $this->fakeToken();
        $user = User::factory()->create();
        $environment = $user->environments()->create([
            'name' => 'Staging',
            'is_default' => true,
            'variables' => [['key' => 'oauth_secret', 'value' => 'from-the-vault', 'secret' => true]],
        ]);

        $response = $this->actingAs($user)->postJson('/api/proxy', [
            'method' => 'GET',
            'url' => 'https://api.example.com/users',
            'environment_id' => $environment->id,
            'auth' => $this->config(['client_secret' => '{{oauth_secret}}']),
        ])->assertOk();

        Http::assertSent(fn ($request) => $request->url() === self::TOKEN_URL
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('client-1:from-the-vault')));
        $this->assertStringNotContainsString('from-the-vault', $response->getContent());
    }

    public function test_the_ttl_is_capped_however_long_the_server_claims(): void
    {
        // A server promising a year-long token should not pin one in our cache
        // for a year.
        $this->fakeToken('tok-long', 31536000);
        Cache::flush();

        (new OAuth2TokenProvider)->token($this->config());

        $key = 'oauth2:'.hash('sha256', implode("\0", [
            self::TOKEN_URL, 'client-1', 's3cret', '', '', 'basic',
        ]));
        $this->assertSame('tok-long', Cache::get($key));
    }
}
