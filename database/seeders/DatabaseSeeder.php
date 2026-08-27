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
     * The seeded accounts: one admin, two ordinary users for testing flows
     * that need more than one account (organisations, admin actions, sharing).
     *
     * Passwords come from env vars; any left unset gets a random one printed
     * once, so a known default can never ship to production.
     */
    private const ACCOUNTS = [
        ['email' => 'admin@apispi.com', 'name' => 'Admin', 'is_admin' => true, 'env' => 'security.admin_password'],
        ['email' => 'bot89@apispi.com', 'name' => 'User 1', 'is_admin' => false, 'env' => 'security.bot_password'],
        ['email' => 'bot97@apispi.com', 'name' => 'User 2', 'is_admin' => false, 'env' => 'security.bot_password'],
    ];

    public function run(): void
    {
        foreach (self::ACCOUNTS as $account) {
            $password = config($account['env']);

            if (! $password) {
                $password = Str::password(20);
                $this->command?->warn(sprintf(
                    '%s not set — generated password for %s: %s',
                    strtoupper(str_replace('security.', '', $account['env'])),
                    $account['email'],
                    $password
                ));
                $this->command?->warn('Store it now; it will not be shown again.');
            }

            // withTrashed: re-seeding must revive a deactivated seed account
            // rather than fail the unique email check against a hidden row.
            $user = User::withTrashed()->updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'password' => Hash::make($password),
                    'is_admin' => $account['is_admin'],
                ]
            );

            if ($user->trashed()) {
                $user->restore();
            }

            // Seeded accounts are usable immediately, no email flow.
            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }
        }

        // Connectors, agents, skills, tools, prompts, and resources. Idempotent
        // and activation-preserving, so it is safe on every deploy.
        $this->call(CatalogSeeder::class);
    }
}
