<?php

namespace App\Console\Commands;

use App\Models\Monitor;
use App\Services\Monitors\MonitorRunner;
use Illuminate\Console\Command;

class RunDueMonitors extends Command
{
    protected $signature = 'monitors:run
                            {--id= : Run one monitor by id, ignoring its schedule}';

    protected $description = 'Run every monitor whose interval has elapsed';

    public function handle(MonitorRunner $runner): int
    {
        if ($id = $this->option('id')) {
            $monitor = Monitor::find($id);

            if (! $monitor) {
                $this->error("No monitor with id {$id}.");

                return self::FAILURE;
            }

            $result = $runner->run($monitor);
            $this->line(sprintf('%s: %s', $monitor->name, $result->passed ? 'passed' : 'FAILED'));

            return self::SUCCESS;
        }

        $ran = 0;

        // Dueness is evaluated in PHP so the check behaves identically on
        // SQLite and MySQL. The enabled set is small enough to walk.
        foreach (Monitor::enabled()->get() as $monitor) {
            if (! $monitor->isDue()) {
                continue;
            }

            $result = $runner->run($monitor);
            $ran++;

            $this->line(sprintf('%s: %s', $monitor->name, $result->passed ? 'passed' : 'FAILED'));
        }

        $this->info($ran === 0 ? 'No monitors due.' : "Ran {$ran} monitor(s).");

        return self::SUCCESS;
    }
}
