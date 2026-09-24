<?php

namespace Tests\Feature;

use App\Models\Environment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnvironmentAuthInheritanceTest extends TestCase
{
    use RefreshDatabase;

    private function environment(User $user, ?array $auth, array $variables = []): Environment
    {
        return $user->environments()->create([
            'name' => 'Staging',
            'is_default' => true,
            'variables' => $variables,
            'auth' => $auth,
        ]);
    }

    private function fake(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);
    }

    /** A collection of one step, so a run exercises the inherited config. */
    private function collection(User $user, ?array $auth)
    {
        $saved = $user->savedRequests()->create([
            'name' => 'List users', 'protocol' => 'rest', 'method' => 'GET',
            'url' => 'https://api.example.com/users', 'auth' => $auth,
        ]);
        $collection = $user->collections()->create(['name' => 'Smoke']);
        $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => 0]);

        return $collection;
    }

    // ------------------------------------------------------------- the proxy

    public function test_a_request_with_no_auth_of_its_own_inherits_the_environments(): void
    {
        $this->fake();
        $user = User::factory()->create();
        $environment = $this->environment($user, ['scheme' => 'bearer', 'token' => 'env-token']);

        $this->actingAs($user)->postJson('/api/proxy', [
            'method' => 'GET',
            'url' => 'https://api.example.com/users',
            'environment_id' => $environment->id,
        ])->assertOk();

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer env-token'));
    }

    public function test_an_explicit_inherit_does_the_same(): void
    {
        $this->fake();
        $user = User::factory()->create();
        $environment = $this->environment($user, ['scheme' => 'bearer', 'token' => 'env-token']);

        $this->actingAs($user)->postJson('/api/proxy', [
            'method' => 'GET',
            'url' => 'https://api.example.com/users',
            'environment_id' => $environment->id,
            'auth' => ['scheme' => 'inherit'],
        ])->assertOk();

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer env-token'));
    }

    public function test_none_is_the_explicit_opt_out(): void
    {
        // One request in an authenticated environment that must go out bare —
        // a public health check, say.
        $this->fake();
        $user = User::factory()->create();
        $environment = $this->environment($user, ['scheme' => 'bearer', 'token' => 'env-token']);

        $this->actingAs($user)->postJson('/api/proxy', [
            'method' => 'GET',
            'url' => 'https://api.example.com/health',
            'environment_id' => $environment->id,
            'auth' => ['scheme' => 'none'],
        ])->assertOk();

        Http::assertSent(fn ($r) => ! $r->hasHeader('Authorization'));
    }

    public function test_the_requests_own_auth_wins_over_the_environments(): void
    {
        $this->fake();
        $user = User::factory()->create();
        $environment = $this->environment($user, ['scheme' => 'bearer', 'token' => 'env-token']);

        $this->actingAs($user)->postJson('/api/proxy', [
            'method' => 'GET',
            'url' => 'https://api.example.com/users',
            'environment_id' => $environment->id,
            'auth' => ['scheme' => 'bearer', 'token' => 'request-token'],
        ])->assertOk();

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer request-token'));
    }

    public function test_an_environment_without_auth_changes_nothing(): void
    {
        $this->fake();
        $user = User::factory()->create();
        $environment = $this->environment($user, null);

        $this->actingAs($user)->postJson('/api/proxy', [
            'method' => 'GET',
            'url' => 'https://api.example.com/users',
            'environment_id' => $environment->id,
        ])->assertOk();

        Http::assertSent(fn ($r) => ! $r->hasHeader('Authorization'));
    }

    public function test_an_inherited_credential_can_be_a_secret_variable(): void
    {
        $this->fake();
        $user = User::factory()->create();
        $environment = $this->environment(
            $user,
            ['scheme' => 'bearer', 'token' => '{{api_token}}'],
            [['key' => 'api_token', 'value' => 'from-the-vault', 'secret' => true]]
        );

        $response = $this->actingAs($user)->postJson('/api/proxy', [
            'method' => 'GET',
            'url' => 'https://api.example.com/users',
            'environment_id' => $environment->id,
        ])->assertOk();

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer from-the-vault'));
        $this->assertStringNotContainsString('from-the-vault', $response->getContent());
    }

    public function test_a_caller_cannot_spoof_the_inherited_config(): void
    {
        // The inherited config travels as a request attribute, never a payload
        // key, so sending one must have no effect.
        $this->fake();
        $user = User::factory()->create();
        $environment = $this->environment($user, null);

        $this->actingAs($user)->postJson('/api/proxy', [
            'method' => 'GET',
            'url' => 'https://api.example.com/users',
            'environment_id' => $environment->id,
            'environment_auth' => ['scheme' => 'bearer', 'token' => 'spoofed'],
        ])->assertOk();

        Http::assertSent(fn ($r) => ! $r->hasHeader('Authorization'));
    }

    // -------------------------------------------------------- collection runs

    public function test_a_collection_step_inherits_the_environments_auth(): void
    {
        $this->fake();
        $user = User::factory()->create();
        $this->environment($user, ['scheme' => 'bearer', 'token' => 'env-token']);
        $collection = $this->collection($user, null);

        $this->actingAs($user)->postJson("/api/collections/{$collection->id}/run")->assertOk();

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer env-token'));
    }

    public function test_a_collection_step_can_opt_out_with_none(): void
    {
        $this->fake();
        $user = User::factory()->create();
        $this->environment($user, ['scheme' => 'bearer', 'token' => 'env-token']);
        $collection = $this->collection($user, ['scheme' => 'none']);

        $this->actingAs($user)->postJson("/api/collections/{$collection->id}/run")->assertOk();

        Http::assertSent(fn ($r) => ! $r->hasHeader('Authorization'));
    }

    public function test_an_inherited_oauth_client_is_fetched_once_for_the_whole_run(): void
    {
        // The point of configuring OAuth on the environment: one token request
        // for the run, not one per step.
        Http::fake([
            'auth.example.com/*' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600], 200),
            'api.example.com/*' => Http::response(['ok' => true], 200),
        ]);

        $user = User::factory()->create();
        $this->environment($user, [
            'scheme' => 'oauth2_client_credentials',
            'token_url' => 'https://auth.example.com/oauth/token',
            'client_id' => 'client-1',
            'client_secret' => 's3cret',
        ]);

        $collection = $user->collections()->create(['name' => 'Smoke']);
        foreach (['One', 'Two', 'Three'] as $position => $name) {
            $saved = $user->savedRequests()->create([
                'name' => $name, 'protocol' => 'rest', 'method' => 'GET',
                'url' => 'https://api.example.com/'.strtolower($name),
            ]);
            $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => $position]);
        }

        $this->actingAs($user)->postJson("/api/collections/{$collection->id}/run")->assertOk();

        Http::assertSentCount(4); // one token request + three steps
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.example.com')
            && $r->hasHeader('Authorization', 'Bearer tok-1'));
    }

    // ----------------------------------------------------- managing the config

    public function test_an_environments_auth_is_saved_and_its_secret_never_returned(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/environments', [
            'name' => 'Staging',
            'auth' => [
                'scheme' => 'oauth2_client_credentials',
                'token_url' => 'https://auth.example.com/oauth/token',
                'client_id' => 'client-1',
                'client_secret' => 'very-secret',
            ],
        ])->assertCreated();

        $this->assertSame('very-secret', Environment::find($response->json('id'))->auth['client_secret']);

        // …but the browser only learns that one is set.
        $response->assertJsonPath('auth.client_secret', '');
        $response->assertJsonPath('auth.has_client_secret', true);
        $this->assertStringNotContainsString('very-secret', $response->getContent());
    }

    public function test_saving_with_a_blank_secret_keeps_the_stored_one(): void
    {
        // The client is never sent the secret, so an unchanged one comes back
        // empty — a naive save would wipe it on every edit.
        $user = User::factory()->create();
        $environment = $this->environment($user, [
            'scheme' => 'oauth2_client_credentials',
            'token_url' => 'https://auth.example.com/oauth/token',
            'client_id' => 'client-1',
            'client_secret' => 'very-secret',
        ]);

        $this->actingAs($user)->putJson("/api/environments/{$environment->id}", [
            'name' => 'Staging renamed',
            'auth' => [
                'scheme' => 'oauth2_client_credentials',
                'token_url' => 'https://auth.example.com/oauth/token',
                'client_id' => 'client-1',
                'client_secret' => '',
            ],
        ])->assertOk();

        $this->assertSame('very-secret', $environment->fresh()->auth['client_secret']);
    }

    public function test_clearing_the_scheme_removes_the_config(): void
    {
        $user = User::factory()->create();
        $environment = $this->environment($user, ['scheme' => 'bearer', 'token' => 'env-token']);

        $this->actingAs($user)->putJson("/api/environments/{$environment->id}", [
            'name' => 'Staging',
            'auth' => ['scheme' => 'none'],
        ])->assertOk();

        $this->assertNull($environment->fresh()->auth);
    }

    public function test_a_duplicated_environment_carries_its_auth(): void
    {
        $user = User::factory()->create();
        $environment = $this->environment($user, ['scheme' => 'bearer', 'token' => 'env-token']);

        $copy = $this->actingAs($user)->postJson("/api/environments/{$environment->id}/duplicate")
            ->assertCreated();

        $this->assertSame(
            ['scheme' => 'bearer', 'token' => 'env-token'],
            Environment::find($copy->json('id'))->auth
        );
    }
}
