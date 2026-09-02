<?php

namespace Tests\Feature;

use App\Models\InspectionReport;
use App\Models\McpProxy;
use App\Models\User;
use App\Services\Mcp\McpClient;
use App\Services\Mcp\McpReplayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpReplayTest extends TestCase
{
    use RefreshDatabase;

    /** The results the fake target returns per replayed call. */
    private array $targetResults = [];

    private function fakeReplayer(): void
    {
        $results = &$this->targetResults;

        $this->app->bind(McpReplayer::class, function () use (&$results) {
            return new class($results) extends McpReplayer {
                public function __construct(private array &$results) { parent::__construct(); }

                public function replay(McpProxy $proxy, string $targetUrl, bool $safeMode = true, $clientFactory = null): array
                {
                    $results = $this->results;

                    return parent::replay($proxy, $targetUrl, $safeMode, function () use ($results) {
                        return new class($results) extends McpClient {
                            public function __construct(private array $results) { parent::__construct('https://target.test/mcp'); }
                            public function initialize(string $a = 'x', string $b = '0'): array { return ['protocolVersion' => '2025-06-18']; }
                            public function rawRequest(string $method, array $params = []): array
                            {
                                $key = $method === 'tools/call' ? ($params['name'] ?? $method) : $method;

                                return ['jsonrpc' => '2.0', 'id' => 1, 'result' => $this->results[$key] ?? []];
                            }
                        };
                    });
                }
            };
        });
    }

    private function proxy(User $user): McpProxy
    {
        $proxy = $user->mcpProxies()->create([
            'name' => 'Acme', 'token' => McpProxy::generateToken(),
            'upstream_url' => 'https://mcp.acme.example.com/tools',
        ]);
        $proxy->exchanges()->create([
            'method' => 'tools/call', 'status' => 200,
            'request' => ['method' => 'tools/call', 'params' => ['name' => 'search', 'arguments' => ['q' => 'x']]],
            'response' => ['result' => ['results' => [['id' => 1]], 'nextCursor' => 'abc']],
        ]);

        return $proxy;
    }

    public function test_an_unchanged_response_shape_is_a_match(): void
    {
        $this->fakeReplayer();
        $this->targetResults = ['search' => ['results' => [['id' => 9]], 'nextCursor' => 'zzz']];

        $user = User::factory()->create();
        $proxy = $this->proxy($user);

        $this->actingAs($user)->postJson("/api/mcp-proxies/{$proxy->id}/replay")
            ->assertOk()
            ->assertJsonPath('passed', true)
            ->assertJsonPath('matched', 1)
            ->assertJsonPath('diverged', 0);
    }

    public function test_a_dropped_field_is_a_regression(): void
    {
        $this->fakeReplayer();
        // nextCursor is gone in the new server version.
        $this->targetResults = ['search' => ['results' => [['id' => 9]]]];

        $user = User::factory()->create();
        $proxy = $this->proxy($user);

        $response = $this->actingAs($user)->postJson("/api/mcp-proxies/{$proxy->id}/replay")
            ->assertStatus(422)
            ->assertJsonPath('passed', false)
            ->assertJsonPath('diverged', 1);

        $this->assertSame('$.nextCursor', $response->json('steps.0.shape.removed.0.path'));
    }

    public function test_destructive_calls_are_skipped_in_safe_mode(): void
    {
        $this->fakeReplayer();
        // search matches (so the run passes); delete_user must be skipped.
        $this->targetResults = ['search' => ['results' => [], 'nextCursor' => 'x']];

        $user = User::factory()->create();
        $proxy = $this->proxy($user);
        $proxy->exchanges()->create([
            'method' => 'tools/call', 'status' => 200,
            'request' => ['method' => 'tools/call', 'params' => ['name' => 'delete_user', 'arguments' => ['id' => 1]]],
            'response' => ['result' => ['ok' => true]],
        ]);

        $this->actingAs($user)->postJson("/api/mcp-proxies/{$proxy->id}/replay")
            ->assertOk()
            ->assertJsonPath('skipped', 1)
            ->assertJsonPath('diverged', 0);
    }

    public function test_it_persists_a_replay_report(): void
    {
        $this->fakeReplayer();
        $this->targetResults = ['search' => ['results' => [], 'nextCursor' => 'x']];

        $user = User::factory()->create();
        $proxy = $this->proxy($user);

        $this->actingAs($user)->postJson("/api/mcp-proxies/{$proxy->id}/replay")->assertOk();
        $this->assertDatabaseHas('inspection_reports', ['type' => 'replay', 'user_id' => $user->id]);
    }

    public function test_an_internal_target_is_refused(): void
    {
        $user = User::factory()->create();
        $proxy = $this->proxy($user);

        $this->actingAs($user)->postJson("/api/mcp-proxies/{$proxy->id}/replay", [
            'target_url' => 'http://169.254.169.254/mcp',
        ])->assertStatus(422)->assertJsonValidationErrors(['target_url']);
    }

    public function test_a_user_cannot_replay_another_users_recorder(): void
    {
        $owner = User::factory()->create();
        $proxy = $this->proxy($owner);

        $this->actingAs(User::factory()->create())
            ->postJson("/api/mcp-proxies/{$proxy->id}/replay")
            ->assertStatus(404);
    }
}
