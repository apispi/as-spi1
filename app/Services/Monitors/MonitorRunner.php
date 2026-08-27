<?php

namespace App\Services\Monitors;

use App\Models\InspectionReport;
use App\Models\Monitor;
use App\Models\MonitorResult;
use App\Notifications\MonitorStatusChanged;
use App\Services\Alerts\AlertDispatcher;
use App\Services\Collections\CollectionRunner;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Executes one monitor: runs its collection, records a history point, and
 * alerts when the pass/fail status changes.
 *
 * Alerts fire on transition only — passing to failing, and back — rather than
 * on every failing run. A monitor that emails every five minutes while an API
 * is down gets muted, and then it is not a monitor any more.
 */
class MonitorRunner
{
    public function __construct(
        private readonly CollectionRunner $runner,
        private readonly AlertDispatcher $alerts,
        private readonly McpDriftDetector $drift = new McpDriftDetector,
    ) {
    }

    public function run(Monitor $monitor): MonitorResult
    {
        if ($monitor->type === Monitor::TYPE_MCP_DRIFT) {
            return $this->runDrift($monitor);
        }

        $monitor->loadMissing(['collection', 'environment', 'user']);

        try {
            $result = $this->runner->run($monitor->collection, $monitor->environment);
        } catch (Throwable $e) {
            // An unexpected failure is itself a failing result: a monitor that
            // silently stops reporting is worse than one reporting an error.
            Log::warning('Monitor run failed', ['monitor' => $monitor->id, 'error' => $e->getMessage()]);

            $result = [
                'passed' => false,
                'total' => 0,
                'passed_count' => 0,
                'failed_count' => 0,
                'skipped_count' => 0,
                'time_ms' => 0,
                'steps' => [],
                'error' => $e->getMessage(),
            ];
        }

        $report = InspectionReport::create([
            'user_id' => $monitor->user_id,
            'type' => 'collection_run',
            'summary' => sprintf(
                '%s — %d/%d passed',
                $monitor->name,
                $result['passed_count'] ?? 0,
                $result['total'] ?? 0
            ),
            'data' => $result + ['monitor' => ['id' => $monitor->id, 'name' => $monitor->name]],
        ]);

        $entry = $monitor->results()->create([
            'inspection_report_id' => $report->id,
            'passed' => (bool) $result['passed'],
            'time_ms' => (int) ($result['time_ms'] ?? 0),
            'passed_count' => (int) ($result['passed_count'] ?? 0),
            'total' => (int) ($result['total'] ?? 0),
            'summary' => $result['error'] ?? $this->summarise($result),
        ]);

        $this->applyStatus($monitor, (bool) $result['passed'], $entry);
        $this->trim($monitor);

        return $entry;
    }

    /**
     * A drift run: snapshot the target's tools/list and compare with the
     * previous snapshot. Drift records a failing result and alerts once; the
     * new shape then becomes the baseline, so the status returns to passing
     * without emitting a misleading "recovered" alert.
     */
    private function runDrift(Monitor $monitor): MonitorResult
    {
        $monitor->loadMissing('user');

        $previous = $monitor->results()->first()?->driftSnapshot();

        try {
            $current = $this->drift->snapshot((string) $monitor->target_url);
        } catch (Throwable $e) {
            // Unreachable is a plain failure, not drift.
            $report = InspectionReport::create([
                'user_id' => $monitor->user_id,
                'type' => 'mcp_drift',
                'summary' => $monitor->name.' — unreachable',
                'data' => ['error' => $e->getMessage(), 'monitor' => ['id' => $monitor->id, 'name' => $monitor->name]],
            ]);

            $entry = $monitor->results()->create([
                'inspection_report_id' => $report->id,
                'passed' => false,
                'time_ms' => 0,
                'passed_count' => 0,
                'total' => 0,
                'summary' => 'Unreachable: '.$e->getMessage(),
            ]);

            $this->applyStatus($monitor, false, $entry);
            $this->trim($monitor);

            return $entry;
        }

        $diff = $previous === null
            ? ['drifted' => false, 'added' => [], 'removed' => [], 'changed' => []]
            : $this->drift->compare($previous, $current['snapshot']);

        $summary = $previous === null
            ? sprintf('Baseline captured: %d tool(s).', $current['tools'])
            : $this->drift->describe($diff);

        $report = InspectionReport::create([
            'user_id' => $monitor->user_id,
            'type' => 'mcp_drift',
            'summary' => $monitor->name.' — '.$summary,
            'data' => [
                'snapshot' => $current['snapshot'],
                'diff' => $diff,
                'monitor' => ['id' => $monitor->id, 'name' => $monitor->name],
                'target_url' => $monitor->target_url,
            ],
        ]);

        $entry = $monitor->results()->create([
            'inspection_report_id' => $report->id,
            'passed' => ! $diff['drifted'],
            'time_ms' => 0,
            'passed_count' => $current['tools'],
            'total' => $current['tools'],
            'summary' => $summary,
        ]);

        $this->applyStatus($monitor, ! $diff['drifted'], $entry);

        // The changed surface is the new contract; comparing every future run
        // against the pre-drift shape would re-alert forever. Resetting to
        // passing after the alert also prevents a bogus "recovered" email on
        // the next quiet run.
        if ($diff['drifted']) {
            $monitor->forceFill(['last_status' => Monitor::STATUS_PASSING])->save();
        }

        $this->trim($monitor);

        return $entry;
    }

    /**
     * Update the monitor's status and notify on a transition.
     */
    private function applyStatus(Monitor $monitor, bool $passed, MonitorResult $entry): void
    {
        $previous = $monitor->last_status;
        $next = $passed ? Monitor::STATUS_PASSING : Monitor::STATUS_FAILING;

        $monitor->forceFill([
            'last_status' => $next,
            'last_run_at' => now(),
            'consecutive_failures' => $passed ? 0 : $monitor->consecutive_failures + 1,
        ])->save();

        // The first run establishes a baseline rather than announcing a
        // "recovery" or a brand-new outage.
        $isTransition = $previous !== Monitor::STATUS_UNKNOWN && $previous !== $next;

        if (! $isTransition || ! $monitor->alerts_enabled) {
            return;
        }

        try {
            $monitor->user->notify(new MonitorStatusChanged($monitor, $entry, $next));
        } catch (Throwable $e) {
            // A mail misconfiguration must not lose the run we just recorded.
            Log::warning('Monitor alert failed', ['monitor' => $monitor->id, 'error' => $e->getMessage()]);
        }

        // Webhook channels are independent of mail: they work with no SMTP
        // configured at all, and one failing destination must not stop the
        // others. The dispatcher records its own failures.
        $this->alerts->dispatch($monitor, $entry, $next);
    }

    private function summarise(array $result): string
    {
        if ($result['passed']) {
            return 'All steps passed.';
        }

        foreach ($result['steps'] ?? [] as $step) {
            if (! ($step['passed'] ?? true) && ! ($step['skipped'] ?? false)) {
                return $step['error'] ?: sprintf('Step "%s" failed.', $step['name'] ?? '?');
            }
        }

        return 'One or more steps failed.';
    }

    private function trim(Monitor $monitor): void
    {
        $cutoff = $monitor->results()->skip(Monitor::RETENTION)->value('id');

        if ($cutoff !== null) {
            $monitor->results()->where('id', '<=', $cutoff)->delete();
        }
    }
}
