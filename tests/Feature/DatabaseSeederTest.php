<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_three_standard_accounts(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', ['email' => 'admin@apispi.com', 'name' => 'Admin', 'is_admin' => true]);
        $this->assertDatabaseHas('users', ['email' => 'bot89@apispi.com', 'name' => 'User 1', 'is_admin' => false]);
        $this->assertDatabaseHas('users', ['email' => 'bot97@apispi.com', 'name' => 'User 2', 'is_admin' => false]);

        // Usable immediately: no email-verification flow for seeded accounts.
        $this->assertSame(0, User::whereNull('email_verified_at')->count());
    }

    public function test_reseeding_is_idempotent_and_revives_a_deactivated_seed_account(): void
    {
        $this->seed(DatabaseSeeder::class);

        // An admin deactivated a seed account; re-seeding must revive it
        // rather than fail the unique-email check against the hidden row.
        User::where('email', 'bot89@apispi.com')->first()->delete();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(3, User::count());
        $this->assertNull(User::where('email', 'bot89@apispi.com')->first()->deleted_at);
    }
}
