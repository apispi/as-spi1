<?php

namespace Tests\Feature;

use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Query-count guards for the two monitor-shaped list endpoints.
 *
 * Both presented each monitor one at a time, so uptime (two counts) and the
 * alert-channel ids (one more) were fetched per row. The cost therefore grew
 * with the number of monitors rather than staying flat — on the monitors list
 * every time it is opened, and on the public status page, which is
 * unauthenticated and so the one place where per-row cost is somebody else's
 * to trigger.
 *
 * These assert the shape rather than an exact number: adding monitors must not
 * add queries.
 */
class MonitorListQueryCountTest extends TestCase
{
    use RefreshDatabase;

    /** Monitors with run history, so uptime has something to compute over. */
    private function monitors(User $user, int $count): array
    {
        $made = [];
        // Names are unique per workspace, and this helper gets called twice in
        // the same test to grow the list.
        $offset = $user->monitors()->count();

        for ($i = $offset; $i < $offset + $count; $i++) {
            $collection = $user->collections()->create(['name' => 'Suite '.$i]);
            $monitor = $user->monitors()->create([
                'name' => 'Monitor '.$i,
                'collection_id' => $collection->id,
                'interval_minutes' => 60,
            ]);

            foreach ([true, false, true] as $passed) {
                $monitor->results()->create([
                    'passed' => $passed, 'time_ms' => 10, 'passed_count' => 1, 'total' => 1, 'summary' => 'x',
                ]);
            }

            $made[] = $monitor;
        }

        return $made;
    }

    private function countQueries(callable $work): int
    {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $work();

        return $queries;
    }

    public function test_the_monitors_list_does_not_cost_more_queries_per_monitor(): void
    {
        $user = User::factory()->create();
        $this->monitors($user, 2);
        $this->actingAs($user);

        // Warm anything cached on the first request so the comparison is fair.
        $this->getJson('/api/monitors')->assertOk();

        $forTwo = $this->countQueries(fn () => $this->getJson('/api/monitors')->assertOk());

        $this->monitors($user, 6);
        $forEight = $this->countQueries(fn () => $this->getJson('/api/monitors')->assertOk());

        $this->assertSame(
            $forTwo,
            $forEight,
            "Listing 8 monitors cost {$forEight} queries against {$forTwo} for 2 — the work is still per-monitor."
        );
    }

    public function test_the_public_status_page_does_not_cost_more_queries_per_monitor(): void
    {
        $user = User::factory()->create();
        $monitors = $this->monitors($user, 8);

        $small = $user->statusPages()->create(['name' => 'Small', 'token' => StatusPage::generateToken()]);
        $large = $user->statusPages()->create(['name' => 'Large', 'token' => StatusPage::generateToken()]);

        $small->monitors()->attach($monitors[0]->id, ['position' => 0]);
        foreach ($monitors as $i => $monitor) {
            $large->monitors()->attach($monitor->id, ['position' => $i]);
        }

        $this->getJson("/api/status/{$small->token}")->assertOk();

        $forOne = $this->countQueries(fn () => $this->getJson("/api/status/{$small->token}")->assertOk());
        $forEight = $this->countQueries(fn () => $this->getJson("/api/status/{$large->token}")->assertOk());

        // Not flat, and deliberately so: the history strip is one query per
        // monitor, and trimming that needs a per-monitor window not worth the
        // raw SQL for a page capped at a handful of monitors. What this pins is
        // that it is *one* per monitor and not three — uptime used to be two
        // more. Asserting flatness here would be asserting something the code
        // does not do.
        $perMonitor = ($forEight - $forOne) / 7;

        $this->assertLessThanOrEqual(
            1,
            $perMonitor,
            "Each extra monitor costs {$perMonitor} queries on the public page ({$forEight} for 8 vs {$forOne} for 1)."
        );
    }

    public function test_uptime_is_still_correct_for_a_list(): void
    {
        // The optimisation must not change the number it reports: two of every
        // three results pass above.
        $user = User::factory()->create();
        $this->monitors($user, 3);

        $uptimes = collect($this->actingAs($user)->getJson('/api/monitors')->assertOk()->json())
            ->pluck('uptime')->unique()->values();

        $this->assertSame([66.7], $uptimes->all());
    }

    public function test_a_monitor_with_no_history_reports_no_uptime(): void
    {
        $user = User::factory()->create();
        $collection = $user->collections()->create(['name' => 'Fresh']);
        $user->monitors()->create([
            'name' => 'Never run', 'collection_id' => $collection->id, 'interval_minutes' => 60,
        ]);

        $this->actingAs($user)->getJson('/api/monitors')
            ->assertOk()
            ->assertJsonPath('0.uptime', null);
    }

    public function test_the_alert_channel_ids_are_still_reported(): void
    {
        $user = User::factory()->create();
        [$monitor] = $this->monitors($user, 1);
        $channel = $user->alertChannels()->create([
            'name' => 'Ops', 'type' => 'webhook', 'url' => 'https://hooks.example.com/x',
        ]);
        $monitor->alertChannels()->attach($channel->id);

        $this->actingAs($user)->getJson('/api/monitors')
            ->assertOk()
            ->assertJsonPath('0.alert_channel_ids.0', $channel->id);
    }
}
