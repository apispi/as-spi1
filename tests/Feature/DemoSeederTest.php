<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_a_tagged_demo_workspace(): void
    {
        $this->seed(DemoSeeder::class);

        $demo = User::where('email', DemoSeeder::DEMO_EMAIL)->firstOrFail();
        $this->assertTrue($demo->is_demo, 'The demo user must be tagged is_demo.');

        // A realistic workspace hangs off the demo user.
        $this->assertSame(2, $demo->environments()->count());
        $this->assertSame(3, $demo->savedRequests()->count());
        $this->assertSame(1, $demo->collections()->count());
        $this->assertSame(3, $demo->collections()->first()->steps()->count());
        $this->assertGreaterThan(0, $demo->requestHistories()->count());
        $this->assertSame(1, $demo->monitors()->count());
        $this->assertGreaterThan(0, $demo->monitors()->first()->results()->count());
    }

    public function test_re_seeding_is_idempotent(): void
    {
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->assertSame(1, User::where('email', DemoSeeder::DEMO_EMAIL)->count());
        $demo = User::where('email', DemoSeeder::DEMO_EMAIL)->firstOrFail();
        $this->assertSame(3, $demo->savedRequests()->count());     // not doubled
        $this->assertSame(3, $demo->collections()->first()->steps()->count());
    }

    public function test_demo_clear_removes_demo_data_only(): void
    {
        $this->seed(DemoSeeder::class);
        $real = User::factory()->create(['email' => 'real@example.com']);
        $realSaved = $real->savedRequests()->create(['name' => 'Keep me', 'protocol' => 'rest', 'method' => 'GET', 'url' => 'https://x']);

        $demo = User::where('email', DemoSeeder::DEMO_EMAIL)->firstOrFail();
        $demoId = $demo->id;

        $this->artisan('demo:clear --force')->assertExitCode(0);

        // Demo user and everything they owned is gone.
        $this->assertDatabaseMissing('users', ['id' => $demoId]);
        $this->assertDatabaseMissing('saved_requests', ['user_id' => $demoId]);
        $this->assertDatabaseMissing('monitors', ['user_id' => $demoId]);
        $this->assertSame(0, DB::table('organisations')->where('slug', 'demo-co')->count());

        // The real user is untouched.
        $this->assertDatabaseHas('users', ['id' => $real->id]);
        $this->assertDatabaseHas('saved_requests', ['id' => $realSaved->id]);
    }

    /**
     * A sweep rather than a list of tables.
     *
     * demo:clear relies on cascade-on-delete, plus a hand-written list of the
     * tables that do not cascade (sessions, audit_events). That list rots: a
     * table added later with a user reference and no cascade leaves demo rows
     * behind, and nothing says so. This fails when that happens, naming the
     * table.
     */
    public function test_no_table_is_left_holding_a_reference_to_a_cleared_demo_user(): void
    {
        $this->seed(DemoSeeder::class);

        $demo = User::where('email', DemoSeeder::DEMO_EMAIL)->firstOrFail();
        $demoIds = User::where('is_demo', true)->pluck('id')->all();

        // Give the demo user a row in each of the newer tables too, so the
        // sweep is actually exercising them rather than passing on absence.
        $page = $demo->statusPages()->create([
            'name' => 'Demo status', 'token' => \App\Models\StatusPage::generateToken(),
        ]);
        $page->incidents()->create([
            'user_id' => $demo->id, 'title' => 'Demo incident',
            'status' => 'investigating', 'started_at' => now(),
            'updates' => [['at' => now()->toIso8601String(), 'status' => 'investigating', 'body' => 'x']],
        ]);
        \App\Models\WorkspaceActivity::record($demo->id, 'saved_request', 1, 'Demo', 'created');
        \App\Models\WorkspaceInvitation::create([
            'organisation_id' => $demo->organisation_id,
            'invited_by_user_id' => $demo->id,
            'email' => 'invitee@example.com',
            'token_hash' => \App\Models\WorkspaceInvitation::hash('tok'),
            'expires_at' => now()->addDay(),
        ]);

        $this->artisan('demo:clear --force')->assertExitCode(0);

        $leftovers = [];
        $inspected = 0;

        foreach (Schema::getTableListing() as $table) {
            $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

            if ($table === 'users') {
                continue;
            }

            foreach (Schema::getColumnListing($table) as $column) {
                if (! str_ends_with($column, 'user_id')) {
                    continue;
                }

                $inspected++;
                $count = DB::table($table)->whereIn($column, $demoIds)->count();

                if ($count > 0) {
                    $leftovers[] = "{$table}.{$column} ({$count} row(s))";
                }
            }
        }

        // A sweep that silently inspected nothing would pass too, which would be
        // worse than having no test at all.
        $this->assertGreaterThan(
            15,
            $inspected,
            'The sweep found almost no user references, so it is not actually checking anything.'
        );

        $this->assertSame(
            [],
            $leftovers,
            'demo:clear left rows pointing at deleted demo users: '.implode(', ', $leftovers)
        );
    }

    public function test_demo_clear_is_safe_with_nothing_to_remove(): void
    {
        $this->artisan('demo:clear --force')->expectsOutputToContain('No demo data')->assertExitCode(0);
    }
}
