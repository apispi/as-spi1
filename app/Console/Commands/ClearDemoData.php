<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes all seeded demo data — every user flagged is_demo and everything
 * they own (which cascades on delete), plus the empty demo organisation.
 * Real accounts are never touched.
 */
class ClearDemoData extends Command
{
    protected $signature = 'demo:clear {--force : Skip the confirmation prompt}';

    protected $description = 'Remove all seeded demo data (users flagged is_demo and their content)';

    public function handle(): int
    {
        $demoUsers = User::withTrashed()->where('is_demo', true)->get();

        if ($demoUsers->isEmpty()) {
            $this->info('No demo data to remove.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Delete {$demoUsers->count()} demo user(s) and all their data?")) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        foreach ($demoUsers as $user) {
            DB::transaction(function () use ($user) {
                // The two tables that do not cascade on user delete.
                DB::table('sessions')->where('user_id', $user->id)->delete();
                AuditEvent::where('user_id', $user->id)->delete();
                $user->forceDelete();
            });
        }

        // Remove the demo organisation if it is now empty.
        Organisation::where('slug', 'demo-co')
            ->whereDoesntHave('users')
            ->delete();

        $this->info("Removed {$demoUsers->count()} demo user(s) and their data.");

        return self::SUCCESS;
    }
}
