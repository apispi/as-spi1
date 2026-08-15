<?php

namespace Tests\Feature;

use App\Models\RequestHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VariableResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function userWithEnvironment(array $variables, bool $isDefault = false): array
    {
        $user = User::factory()->create();
        $env = $user->environments()->create([
            'name' => 'Staging',
            'variables' => $variables,
            'is_default' => $isDefault,
        ]);

        return [$user, $env];
    }

    public function test_substitutes_variables_in_the_url_headers_and_body(): void
    {
        Http::fake(['api.staging.example.com/*' => Http::response(['ok' => true], 200)]);

        [$user, $env] = $this->userWithEnvironment([
            ['key' => 'host', 'value' => 'api.staging.example.com', 'secret' => false],
            ['key' => 'token', 'value' => 'staging-token', 'secret' => false],
        ]);

        $this->actingAs($user)->postJson('/api/proxy', [
            'environment_id' => $env->id,
            'url' => 'https://{{host}}/v1/users',
            'method' => 'POST',
            'headers' => ['Authorization' => 'Bearer {{token}}'],
            'body' => '{"host":"{{host}}"}',
        ])->assertOk();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.staging.example.com/v1/users'
                && $request->header('Authorization')[0] === 'Bearer staging-token'
                && $request->body() === '{"host":"api.staging.example.com"}';
        });
    }

    public function test_reports_the_environment_and_unresolved_names(): void
    {
        Http::fake(['api.staging.example.com/*' => Http::response(['ok' => true], 200)]);

        [$user, $env] = $this->userWithEnvironment([
            ['key' => 'host', 'value' => 'api.staging.example.com', 'secret' => false],
        ]);

        $response = $this->actingAs($user)->postJson('/api/proxy', [
            'environment_id' => $env->id,
            'url' => 'https://{{host}}/v1/users',
            'method' => 'POST',
            'body' => '{"a":"{{missing}}"}',
        ]);

        $response->assertOk()
            ->assertJsonPath('environment.name', 'Staging')
            ->assertJsonPath('environment.resolved', ['host'])
            ->assertJsonPath('environment.unresolved', ['missing']);

        // An unknown placeholder is left as-is rather than silently emptied, so
        // a typo is visible in the request instead of sending an empty value.
        Http::assertSent(fn ($request) => $request->body() === '{"a":"{{missing}}"}');
    }

    public function test_an_unresolved_placeholder_in_the_url_fails_validation_and_says_why(): void
    {
        [$user, $env] = $this->userWithEnvironment([
            ['key' => 'host', 'value' => 'api.staging.example.com', 'secret' => false],
        ]);

        // Left in place, "{{missing}}" is not a valid URL — the request is
        // rejected rather than sent somewhere unintended, and the response
        // still names the placeholder that had no value.
        $this->actingAs($user)->postJson('/api/proxy', [
            'environment_id' => $env->id,
            'url' => 'https://{{missing}}/v1',
            'method' => 'GET',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['url'])
            ->assertJsonPath('environment.unresolved', ['missing']);
    }

    public function test_the_default_environment_applies_only_when_a_placeholder_is_present(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        [$user] = $this->userWithEnvironment([
            ['key' => 'host', 'value' => 'api.staging.example.com', 'secret' => false],
        ], isDefault: true);

        // No placeholder: the request is untouched and no environment is reported.
        $this->actingAs($user)->postJson('/api/proxy', [
            'url' => 'https://api.example.com/plain',
            'method' => 'GET',
        ])->assertOk()->assertJsonMissingPath('environment');

        // Placeholder present: the default environment fills it in.
        $this->actingAs($user)->postJson('/api/proxy', [
            'url' => 'https://{{host}}/v1',
            'method' => 'GET',
        ])->assertOk()->assertJsonPath('environment.name', 'Staging');
    }

    public function test_a_variable_cannot_smuggle_an_internal_host_past_the_ssrf_guard(): void
    {
        // Substitution runs before validation, so the resolved URL — not the
        // template — is what PubliclyRoutableUrl sees.
        [$user, $env] = $this->userWithEnvironment([
            ['key' => 'host', 'value' => '169.254.169.254', 'secret' => false],
        ]);

        $this->actingAs($user)->postJson('/api/proxy', [
            'environment_id' => $env->id,
            'url' => 'http://{{host}}/latest/meta-data/',
            'method' => 'GET',
        ])->assertStatus(422)->assertJsonValidationErrors(['url']);
    }

    public function test_secret_values_are_masked_in_the_echoed_request_and_history(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);

        [$user, $env] = $this->userWithEnvironment([
            ['key' => 'token', 'value' => 'super-secret-value', 'secret' => true],
        ]);

        $response = $this->actingAs($user)->postJson('/api/proxy', [
            'environment_id' => $env->id,
            'url' => 'https://api.example.com/v1',
            'method' => 'POST',
            'headers' => ['Authorization' => 'Bearer {{token}}'],
            'body' => '{"token":"{{token}}"}',
        ])->assertOk();

        // The real secret still goes to the target...
        Http::assertSent(fn ($request) => $request->header('Authorization')[0] === 'Bearer super-secret-value');

        // ...but never comes back to the client or lands in history.
        $this->assertStringNotContainsString('super-secret-value', $response->getContent());
        $this->assertStringContainsString('••••••', $response->json('request_payload'));

        $history = RequestHistory::where('user_id', $user->id)->first();
        $this->assertStringNotContainsString('super-secret-value', (string) $history->body);
    }

    public function test_another_users_environment_cannot_be_selected(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $owner = User::factory()->create();
        $env = $owner->environments()->create([
            'name' => 'Theirs',
            'variables' => [['key' => 'host', 'value' => 'api.example.com', 'secret' => false]],
        ]);
        $other = User::factory()->create();

        $this->actingAs($other)->postJson('/api/proxy', [
            'environment_id' => $env->id,
            'url' => 'https://{{host}}/v1',
            'method' => 'GET',
        ])->assertStatus(422);
    }

    public function test_guests_get_no_substitution(): void
    {
        // The proxy is open to guests; without a user there is no environment
        // to resolve against, so the template reaches validation unchanged.
        $this->postJson('/api/proxy', [
            'url' => 'https://{{host}}/v1',
            'method' => 'GET',
        ])->assertStatus(422)->assertJsonValidationErrors(['url']);
    }

    public function test_works_over_the_v1_api_with_an_environment_name(): void
    {
        Http::fake(['api.staging.example.com/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create();
        $key = $user->generateApiKey();
        $user->environments()->create([
            'name' => 'Staging',
            'variables' => [['key' => 'host', 'value' => 'api.staging.example.com', 'secret' => false]],
        ]);

        $this->withHeader('Authorization', 'Bearer '.$key)
            ->postJson('/api/v1/proxy', [
                'environment' => 'Staging',
                'url' => 'https://{{host}}/v1/users',
                'method' => 'GET',
            ])->assertOk()->assertJsonPath('environment.name', 'Staging');
    }

    public function test_an_unknown_environment_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/proxy', [
            'environment' => 'Nope',
            'url' => 'https://api.example.com/v1',
            'method' => 'GET',
        ])->assertStatus(422);
    }
}
