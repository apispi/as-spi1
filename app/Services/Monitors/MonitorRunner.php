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
    /** How far back to look for a baseline when recent runs were unreachable. */
    private const BASELINE_LOOKBACK = 20;

    public function __construct(
        private readonly CollectionRunner $runner,
        private readonly AlertDispatcher $alerts,
        private readonly McpDriftDetector $drift = new McpDriftDetector,
        private readonly ?SchemaDriftDetector $schemaDrift = null,
    ) {
    }

    public function run(Monitor $monitor): MonitorResult
    {
        if ($monitor->type === Monitor::TYPE_MCP_DRIFT) {
            return $this->runDrift($monitor);
        }

        if (isset(Monitor::SCHEMA_TYPES[$monitor->type])) {
            return $this->runSchemaDrift($monitor, Monitor::SCHEMA_TYPES[$monitor->type]);
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
            // Both statuses, not just the observed one: alerting keys off
            // last_alerted_status, so leaving that at "failing" would make the
            // next quiet run look like a recovery and send the very message
            // this reset exists to prevent.
            $monitor->forceFill([
                'last_status' => Monitor::STATUS_PASSING,
                'last_alerted_status' => Monitor::STATUS_PASSING,
            ])->save();
        }

        $this->trim($monitor);

        return $entry;
    }

    /**
     * Watch a published schema and report what changed since the last run.
     *
     * Only a breaking change fails the monitor. An addition is recorded and
     * passed: paging somebody because a third party added an optional argument
     * is how monitoring gets muted.
     */
    private function runSchemaDrift(Monitor $monitor, string $flavour): MonitorResult
    {
        $monitor->loadMissing('user');
        $detector = $this->schemaDrift ?? app(SchemaDriftDetector::class);
        $started = microtime(true);

        try {
            $current = $detector->fetch((string) $monitor->target_url, $flavour);
        } catch (Throwable $e) {
            return $this->schemaFailure($monitor, $flavour, 'Unreachable: '.$e->getMessage(), $started);
        }

        $previousReport = $this->lastSchemaReport($monitor);
        $baseline = $previousReport?->data['document'] ?? null;
        $baselineHash = $previousReport?->data['hash'] ?? null;

        // First run, or a baseline that was too large to keep: record what is
        // there now and compare from here on.
        if ($baseline === null) {
            return $this->recordSchemaRun($monitor, $flavour, $current, [
                'breaking' => false, 'changed' => false,
                'breaking_count' => 0, 'non_breaking_count' => 0, 'info_count' => 0,
                'summary' => $baselineHash === null
                    ? 'Baseline captured.'
                    : 'Baseline re-captured — the previous schema was too large to keep.',
                'changes' => [],
            ], $started, storeDocument: true);
        }

        // The hash is a cheap skip; when it differs the diff still decides,
        // so a server that merely reorders its types is not reported as drift.
        $diff = $current['hash'] === $baselineHash
            ? ['breaking' => false, 'changed' => false, 'breaking_count' => 0, 'non_breaking_count' => 0,
                'info_count' => 0, 'summary' => 'No schema change.', 'changes' => []]
            : $detector->compare($baseline, $current['document'] ?? [], $flavour);

        // A new baseline is only worth keeping when it actually moved; a
        // hundred identical copies of a schema is not history, it is waste.
        return $this->recordSchemaRun($monitor, $flavour, $current, $diff, $started, storeDocument: $diff['changed']);
    }

    /**
     * The most recent report holding a usable baseline. A run of unreachable
     * checks in between must not lose the schema we last saw.
     */
    private function lastSchemaReport(Monitor $monitor): ?InspectionReport
    {
        foreach ($monitor->results()->take(self::BASELINE_LOOKBACK)->get() as $result) {
            $report = InspectionReport::find($result->inspection_report_id);

            if ($report && ($report->data['document'] ?? null) !== null) {
                return $report;
            }
        }

        return null;
    }

    private function recordSchemaRun(
        Monitor $monitor,
        string $flavour,
        array $current,
        array $diff,
        float $started,
        bool $storeDocument,
    ): MonitorResult {
        $report = InspectionReport::create([
            'user_id' => $monitor->user_id,
            'type' => 'schema_drift',
            'summary' => $monitor->name.' — '.$diff['summary'],
            'data' => [
                'flavour' => $flavour,
                'target_url' => $monitor->target_url,
                'hash' => $current['hash'],
                'size' => $current['size'],
                'document' => $storeDocument ? $current['document'] : null,
                'diff' => $diff,
                'monitor' => ['id' => $monitor->id, 'name' => $monitor->name],
            ],
        ]);

        $entry = $monitor->results()->create([
            'inspection_report_id' => $report->id,
            'passed' => ! $diff['breaking'],
            'time_ms' => (int) round((microtime(true) - $started) * 1000),
            'passed_count' => $diff['changed'] ? 0 : 1,
            'total' => 1,
            'summary' => $diff['summary'],
        ]);

        $this->applyStatus($monitor, ! $diff['breaking'], $entry);

        // The new schema is the contract now; comparing every future run
        // against the pre-drift shape would re-alert forever.
        if ($diff['breaking']) {
            $monitor->forceFill([
                'last_status' => Monitor::STATUS_PASSING,
                'last_alerted_status' => Monitor::STATUS_PASSING,
            ])->save();
        }

        $this->trim($monitor);

        return $entry;
    }

    private function schemaFailure(Monitor $monitor, string $flavour, string $summary, float $started): MonitorResult
    {
        $report = InspectionReport::create([
            'user_id' => $monitor->user_id,
            'type' => 'schema_drift',
            'summary' => $monitor->name.' — '.$summary,
            'data' => [
                'flavour' => $flavour,
                'target_url' => $monitor->target_url,
                'error' => $summary,
                'monitor' => ['id' => $monitor->id, 'name' => $monitor->name],
            ],
        ]);

        $entry = $monitor->results()->create([
            'inspection_report_id' => $report->id,
            'passed' => false,
            'time_ms' => (int) round((microtime(true) - $started) * 1000),
            'passed_count' => 0,
            'total' => 1,
            'summary' => $summary,
        ]);

        $this->applyStatus($monitor, false, $entry);
        $this->trim($monitor);

        return $entry;
    }

    /**
     * Update the monitor's status and notify on a transition.
     */
    private function applyStatus(Monitor $monitor, bool $passed, MonitorResult $entry): void
    {
        // What was last *announced*, which is not the same as what was last
        // observed once a monitor has been snoozed through a change.
        $previous = $monitor->last_alerted_status ?? $monitor->last_status;
        $next = $passed ? Monitor::STATUS_PASSING : Monitor::STATUS_FAILING;
        $snoozed = $monitor->isSnoozed();

        $monitor->forceFill([
            // Always current: the results list and uptime stay honest through
            // a snooze, which is the difference between muting a monitor and
            // turning it off.
            'last_status' => $next,
            'last_run_at' => now(),
            'consecutive_failures' => $passed ? 0 : $monitor->consecutive_failures + 1,
        ] + ($snoozed ? [] : ['last_alerted_status' => $next]))->save();

        // While snoozed nothing is announced, and the announced status is left
        // alone — so a failure that begins during a deploy is reported when
        // the snooze ends, rather than never.
        if ($snoozed) {
            return;
        }

        // The first run establishes a baseline rather than announcing a
        // "recovery" or a brand-new outage.
        $isTransition = $previous !== Monitor::STATUS_UNKNOWN && $previous !== $next;

        if (! $isTransition) {
            return;
        }

        // An in-app notification is recorded on every transition, independent
        // of alert channels — so a failure is seen even with no email/webhook
        // configured. Fanned out to the whole workspace, since monitors are
        // shared. External alerts remain gated on alerts_enabled below.
        \App\Models\UserNotification::recordForWorkspace(
            $monitor->user,
            $passed ? 'monitor_recovered' : 'monitor_failing',
            $passed ? "Monitor recovered: {$monitor->name}" : "Monitor failing: {$monitor->name}",
            $entry->summary,
            '/reports',
        );

        if (! $monitor->alerts_enabled) {
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
