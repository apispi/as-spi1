<?php

namespace App\Services\Reports;

/**
 * Computes a structured diff between two collection-run reports — the "what
 * changed between these two runs" view for regression triage.
 *
 * Steps are matched by position (their index), so a suite run twice lines up
 * step-for-step. Each matched step is classified: regressed (was passing, now
 * failing), fixed, or unchanged; status and timing deltas are reported too.
 */
class ReportComparer
{
    /**
     * @param  array  $a  the earlier run's data (a collection_run report)
     * @param  array  $b  the later run's data
     */
    public function collectionRun(array $a, array $b): array
    {
        $stepsA = collect($a['steps'] ?? [])->keyBy('index');
        $stepsB = collect($b['steps'] ?? [])->keyBy('index');

        $rows = [];
        $regressed = 0;
        $fixed = 0;

        foreach ($stepsA->keys()->merge($stepsB->keys())->unique()->sort()->values() as $index) {
            $sa = $stepsA->get($index);
            $sb = $stepsB->get($index);

            if ($sa && ! $sb) {
                $rows[] = ['index' => $index, 'name' => $sa['name'] ?? '?', 'verdict' => 'removed'];

                continue;
            }
            if (! $sa && $sb) {
                $rows[] = ['index' => $index, 'name' => $sb['name'] ?? '?', 'verdict' => 'added'];

                continue;
            }

            $passedA = (bool) ($sa['passed'] ?? false);
            $passedB = (bool) ($sb['passed'] ?? false);

            $verdict = match (true) {
                $passedA && ! $passedB => 'regressed',
                ! $passedA && $passedB => 'fixed',
                default => 'unchanged',
            };
            $verdict === 'regressed' && $regressed++;
            $verdict === 'fixed' && $fixed++;

            $timeA = (int) ($sa['time_ms'] ?? 0);
            $timeB = (int) ($sb['time_ms'] ?? 0);

            $rows[] = [
                'index' => $index,
                'name' => $sb['name'] ?? $sa['name'] ?? '?',
                'verdict' => $verdict,
                'status_a' => $sa['status'] ?? null,
                'status_b' => $sb['status'] ?? null,
                'status_changed' => ($sa['status'] ?? null) !== ($sb['status'] ?? null),
                'time_a_ms' => $timeA,
                'time_b_ms' => $timeB,
                'time_delta_ms' => $timeB - $timeA,
            ];
        }

        return [
            'regressed_count' => $regressed,
            'fixed_count' => $fixed,
            'passed_a' => (int) ($a['passed_count'] ?? 0),
            'passed_b' => (int) ($b['passed_count'] ?? 0),
            'total_a' => (int) ($a['total'] ?? 0),
            'total_b' => (int) ($b['total'] ?? 0),
            'time_a_ms' => (int) ($a['time_ms'] ?? 0),
            'time_b_ms' => (int) ($b['time_ms'] ?? 0),
            'steps' => $rows,
        ];
    }

    /** A one-line headline for the comparison. */
    public function collectionRunHeadline(array $diff): string
    {
        $parts = [sprintf('%d/%d → %d/%d passed', $diff['passed_a'], $diff['total_a'], $diff['passed_b'], $diff['total_b'])];
        if ($diff['regressed_count']) {
            $parts[] = $diff['regressed_count'].' regressed';
        }
        if ($diff['fixed_count']) {
            $parts[] = $diff['fixed_count'].' fixed';
        }

        return implode(' · ', $parts);
    }
}
