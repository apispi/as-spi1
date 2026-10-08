<?php

namespace Tests\Feature;

use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every workspace list endpoint, asserted to cost the same whether it returns
 * two rows or eight.
 *
 * This is a class of regression rather than a one-off: the natural way to add
 * a field to a listing is to compute it in the presenter, which quietly turns
 * one query into one per row. It is invisible in development, where everyone
 * has three of everything, and it is exactly how `open_incidents` on the
 * status-page listing got written.
 *
 * A sweep means the next field added to any of these is caught by this test
 * rather than by somebody with a full account.
 */
class ListEndpointQueryCountTest extends TestCase
{
    use RefreshDatabase;

    private function countQueries(callable $work): int
    {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $work();

        return $queries;
    }

    /**
     * Seed $count rows for an endpoint, run it, and return the query count.
     * Names are offset so the same seeder can be called twice to grow the set.
     */
    private function cost(User $user, string $endpoint, callable $seed, int $from, int $to): int
    {
        for ($i = $from; $i < $to; $i++) {
            $seed($user, $i);
        }

        return $this->countQueries(fn () => $this->getJson($endpoint)->assertOk());
    }

    /**
     * @return array<string, array{0: string, 1: callable}>
     */
    public static function endpoints(): array
    {
        return [
            'saved requests' => ['/api/saved-requests', function (User $u, int $i) {
                $u->savedRequests()->create([
                    'name' => 'Request '.$i, 'protocol' => 'rest', 'method' => 'GET',
                    'url' => 'https://api.example.com/'.$i,
                ]);
            }],
            'collections' => ['/api/collections', function (User $u, int $i) {
                $saved = $u->savedRequests()->create([
                    'name' => 'Step request '.$i, 'protocol' => 'rest', 'method' => 'GET',
                    'url' => 'https://api.example.com/'.$i,
                ]);
                $collection = $u->collections()->create(['name' => 'Collection '.$i]);
                $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => 0]);
            }],
            'environments' => ['/api/environments', function (User $u, int $i) {
                $u->environments()->create([
                    'name' => 'Environment '.$i,
                    'variables' => [['key' => 'base_url', 'value' => 'https://x', 'secret' => false]],
                ]);
            }],
            'alert channels' => ['/api/alert-channels', function (User $u, int $i) {
                $u->alertChannels()->create([
                    'name' => 'Channel '.$i, 'type' => 'webhook',
                    'url' => 'https://hooks.example.com/'.$i,
                ]);
            }],
            'webhook endpoints' => ['/api/webhook-endpoints', function (User $u, int $i) {
                $u->webhookEndpoints()->create([
                    'name' => 'Hook '.$i, 'token' => \Illuminate\Support\Str::random(32),
                ]);
            }],
            'mcp mocks' => ['/api/mcp-mocks', function (User $u, int $i) {
                $u->mcpMocks()->create([
                    'name' => 'Mock '.$i, 'token' => \Illuminate\Support\Str::random(32),
                    'server_name' => 'mock', 'tools' => [],
                ]);
            }],
            'status pages' => ['/api/status-pages', function (User $u, int $i) {
                $page = $u->statusPages()->create([
                    'name' => 'Page '.$i, 'token' => StatusPage::generateToken(),
                ]);
                // An open incident each, so the count has something to count —
                // this is the field that introduced the N+1.
                $page->incidents()->create([
                    'user_id' => $u->id, 'title' => 'Incident '.$i,
                    'status' => 'investigating', 'started_at' => now(),
                    'updates' => [['at' => now()->toIso8601String(), 'status' => 'investigating', 'body' => 'x']],
                ]);
            }],
        ];
    }

    /**
     * @dataProvider endpoints
     */
    public function test_a_list_endpoint_costs_the_same_for_two_rows_as_for_eight(string $endpoint, callable $seed): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // One warm request first, so lazily-resolved singletons are not
        // counted against the smaller set.
        $seed($user, 0);
        $this->getJson($endpoint)->assertOk();

        $forTwo = $this->cost($user, $endpoint, $seed, 1, 2);
        $forEight = $this->cost($user, $endpoint, $seed, 2, 8);

        $this->assertSame(
            $forTwo,
            $forEight,
            "{$endpoint} cost {$forEight} queries for 8 rows against {$forTwo} for 2, "
            .'so some of the work is per row. Move it into the listing query '
            .'(with / withCount) rather than the presenter.'
        );
    }
}
