<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_demo_clear_is_safe_with_nothing_to_remove(): void
    {
        $this->artisan('demo:clear --force')->expectsOutputToContain('No demo data')->assertExitCode(0);
    }
}
