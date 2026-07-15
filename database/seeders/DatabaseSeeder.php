<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create the default admin user. The password comes from the
        // ADMIN_PASSWORD env var; if unset, a random one is generated and
        // printed once so a known default can never ship to production.
        $password = env('ADMIN_PASSWORD');

        if (! $password) {
            $password = Str::password(20);
            $this->command?->warn('ADMIN_PASSWORD not set — generated admin password: '.$password);
            $this->command?->warn('Store it now; it will not be shown again.');
        }

        User::updateOrCreate(
            ['email' => 'admin@apispi.com'],
            [
                'name' => 'Admin',
                'email' => 'admin@apispi.com',
                'password' => Hash::make($password),
                'is_admin' => true,
            ]
        );
    }
}
