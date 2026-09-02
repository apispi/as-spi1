<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FuzzTest extends TestCase
{
    use RefreshDatabase;

    private function saved(User $user, array $attrs = [])
    {
        return $user->savedRequests()->create(array_merge([
            'name' => 'Create user', 'protocol' => 'rest', 'method' => 'POST',
            'url' => 'https://api.example.com/users', 'body' => '{"name":"Ada","age":30}',
        ], $attrs));
    }

    public function test_a_well_behaved_endpoint_passes(): void
    {
        // Accepts the baseline (2xx), rejects everything malformed (4xx).
        Http::fake(['api.example.com/*' => function ($request) {
            $body = json_decode($request->body(), true);
            $ok = is_array($body) && isset($body['name'], $body['age'])
                && is_string($body['name']) && $body['name'] !== ''
                && is_int($body['age']);
            return Http::response($ok ? ['id' => 1] : ['error' => 'bad'], $ok ? 201 : 422);
        }]);

        $user = User::factory()->create();
        $saved = $this->saved($user);

        $this->actingAs($user)->postJson("/api/saved-requests/{$saved->id}/fuzz")
            ->assertOk()
            ->assertJsonPath('passed', true)
            ->assertJsonPath('findings', 0);
    }

    public function test_a_server_error_on_bad_input_is_a_finding(): void
    {
        // Crashes (500) when 'name' is missing.
        Http::fake(['api.example.com/*' => function ($request) {
            $body = json_decode($request->body(), true);
            return Http::response([], is_array($body) && isset($body['name']) ? 201 : 500);
        }]);

        $user = User::factory()->create();
        $saved = $this->saved($user);

        $response = $this->actingAs($user)->postJson("/api/saved-requests/{$saved->id}/fuzz")
            ->assertStatus(422)
            ->assertJsonPath('passed', false);

        $this->assertGreaterThan(0, $response->json('server_errors'));
    }

    public function test_accepting_invalid_input_is_a_finding(): void
    {
        // Always 200 — even for garbage. The endpoint takes anything.
        Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create();
        $saved = $this->saved($user);

        $response = $this->actingAs($user)->postJson("/api/saved-requests/{$saved->id}/fuzz")
            ->assertStatus(422);

        $this->assertGreaterThan(0, $response->json('accepted_invalid'));
    }

    public function test_variables_resolve_and_the_resolved_url_is_ssrf_checked(): void
    {
        $user = User::factory()->create();
        $user->environments()->create(['name' => 'Bad', 'variables' => [['key' => 'host', 'value' => '169.254.169.254', 'secret' => false]], 'is_default' => true]);
        $saved = $this->saved($user, ['url' => 'http://{{host}}/users']);

        $this->actingAs($user)->postJson("/api/saved-requests/{$saved->id}/fuzz")
            ->assertStatus(422)
            ->assertJsonPath('message', 'The resolved URL is not a valid public URL.');
    }

    public function test_it_rejects_non_rest_or_bodyless_requests(): void
    {
        $user = User::factory()->create();

        $mcp = $this->saved($user, ['protocol' => 'mcp']);
        $this->actingAs($user)->postJson("/api/saved-requests/{$mcp->id}/fuzz")->assertStatus(422);

        $noBody = $this->saved($user, ['body' => '']);
        $this->actingAs($user)->postJson("/api/saved-requests/{$noBody->id}/fuzz")->assertStatus(422);
    }

    public function test_a_finding_persists_a_fuzz_report(): void
    {
        Http::fake(['api.example.com/*' => Http::response([], 200)]);
        $user = User::factory()->create();
        $saved = $this->saved($user);

        $this->actingAs($user)->postJson("/api/saved-requests/{$saved->id}/fuzz")->assertStatus(422);
        $this->assertDatabaseHas('inspection_reports', ['type' => 'fuzz', 'user_id' => $user->id]);
    }
}
