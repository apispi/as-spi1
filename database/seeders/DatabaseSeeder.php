<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create the default admin user
        User::updateOrCreate(
            ['email' => 'admin@apispi.com'],
            [
                'name' => 'Admin',
                'email' => 'admin@apispi.com',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'is_admin' => true,
            ]
        );
    }
}
