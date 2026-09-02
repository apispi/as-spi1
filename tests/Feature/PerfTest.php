<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Perf\LoadTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PerfTest extends TestCase
{
    use RefreshDatabase;

    private function saved(User $user, array $attrs = [])
    {
        return $user->savedRequests()->create(array_merge([
            'name' => 'Ping', 'protocol' => 'rest', 'method' => 'GET',
            'url' => 'https://api.example.com/ping',
        ], $attrs));
    }

    public function test_it_profiles_latency_and_success_rate(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create();
        $saved = $this->saved($user);

        $response = $this->actingAs($user)->postJson("/api/saved-requests/{$saved->id}/perf", ['samples' => 10])
            ->assertOk()
            ->assertJsonPath('samples', 10)
            ->assertJsonPath('success', 10)
            ->assertJsonPath('success_rate', 100)
            ->assertJsonPath('status_distribution.200', 10);

        // Latency percentiles are present and ordered.
        $lat = $response->json('latency');
        foreach (['min', 'avg', 'p50', 'p90', 'p95', 'p99', 'max'] as $k) {
            $this->assertArrayHasKey($k, $lat);
            $this->assertNotNull($lat[$k]);
        }
        $this->assertLessThanOrEqual($lat['max'], $lat['min']);
        $this->assertLessThanOrEqual($lat['p99'], $lat['p50']);
    }

    public function test_it_records_error_statuses_in_the_distribution(): void
    {
        // Alternate 200 / 503 across samples.
        $n = 0;
        Http::fake(['api.example.com/*' => function () use (&$n) {
            return Http::response([], (++$n % 2 === 0) ? 503 : 200);
        }]);

        $user = User::factory()->create();
        $saved = $this->saved($user);

        $response = $this->actingAs($user)->postJson("/api/saved-requests/{$saved->id}/perf", ['samples' => 10])
            ->assertOk();

        $this->assertSame(5, $response->json('success'));
        $this->assertSame(50, $response->json('success_rate'));
        $this->assertSame(5, $response->json('status_distribution.503'));
    }

    public function test_samples_are_capped(): void
    {
        $user = User::factory()->create();
        $saved = $this->saved($user);

        $this->actingAs($user)->postJson("/api/saved-requests/{$saved->id}/perf", ['samples' => 999])
            ->assertStatus(422)->assertJsonValidationErrors(['samples']);
    }

    public function test_variables_resolve_and_the_resolved_url_is_ssrf_checked(): void
    {
        $user = User::factory()->create();
        $user->environments()->create([
            'name' => 'Bad',
            'variables' => [['key' => 'host', 'value' => '169.254.169.254', 'secret' => false]],
            'is_default' => true,
        ]);
        $saved = $this->saved($user, ['url' => 'http://{{host}}/ping']);

        $this->actingAs($user)->postJson("/api/saved-requests/{$saved->id}/perf")
            ->assertStatus(422)
            ->assertJsonPath('message', 'The resolved URL is not a valid public URL.');
    }

    public function test_it_persists_a_perf_report_and_is_workspace_scoped(): void
    {
        Http::fake(['api.example.com/*' => Http::response([], 200)]);

        $owner = User::factory()->create();
        $saved = $this->saved($owner);

        $this->actingAs($owner)->postJson("/api/saved-requests/{$saved->id}/perf", ['samples' => 3])->assertOk();
        $this->assertDatabaseHas('inspection_reports', ['type' => 'perf', 'user_id' => $owner->id]);

        // A stranger (different workspace) cannot profile it.
        $this->actingAs(User::factory()->create())
            ->postJson("/api/saved-requests/{$saved->id}/perf")->assertStatus(404);
    }

    public function test_non_rest_requests_are_rejected(): void
    {
        $user = User::factory()->create();
        $saved = $this->saved($user, ['protocol' => 'mcp']);

        $this->actingAs($user)->postJson("/api/saved-requests/{$saved->id}/perf")->assertStatus(422);
    }

    public function test_the_load_tester_computes_percentiles(): void
    {
        // Unit-level: a deterministic executor returning fixed latencies.
        $executor = new class extends \App\Services\Collections\RequestExecutor {
            public array $times = [10, 20, 30, 40, 50, 60, 70, 80, 90, 100];
            public int $i = 0;
            public function send(array $request): array
            {
                return ['ok' => true, 'status' => 200, 'time_ms' => $this->times[$this->i++], 'headers' => [], 'body' => '', 'error' => null];
            }
        };

        $result = (new LoadTester($executor))->run(['protocol' => 'rest', 'method' => 'GET', 'url' => 'https://x'], 10);

        $this->assertSame(10, $result['latency']['min']);
        $this->assertSame(100, $result['latency']['max']);
        $this->assertSame(55, $result['latency']['avg']);
        $this->assertSame(50, $result['latency']['p50']);
        $this->assertSame(100, $result['latency']['p99']);
    }
}
