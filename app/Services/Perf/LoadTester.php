<?php

namespace App\Services\Perf;

use App\Services\Collections\RequestExecutor;

/**
 * Profiles an endpoint's performance by sending a bounded batch of samples and
 * summarising the latency distribution and error rate.
 *
 * Deliberately sequential and capped: a hosted tool must not become a DoS
 * weapon, and each sample goes through the shared RequestExecutor so the SSRF
 * guard applies. Sequential sampling gives clean per-request latency (p95/p99
 * spikes, intermittent errors) without concurrent hammering.
 */
class LoadTester
{
    /** Hard ceiling on samples per run. */
    public const MAX_SAMPLES = 100;

    public function __construct(private readonly RequestExecutor $executor)
    {
    }

    /**
     * @param  array  $request  resolved {protocol,method,url,headers,body}
     */
    public function run(array $request, int $samples): array
    {
        $samples = max(1, min(self::MAX_SAMPLES, $samples));

        $latencies = [];
        $statuses = [];
        $success = 0;
        $errors = 0;
        $started = microtime(true);

        for ($i = 0; $i < $samples; $i++) {
            $response = $this->executor->send($request);

            if (! $response['ok'] || $response['status'] === null) {
                $errors++;

                continue;
            }

            $latencies[] = $response['time_ms'];
            $status = (int) $response['status'];
            $statuses[$status] = ($statuses[$status] ?? 0) + 1;

            if ($status < 400) {
                $success++;
            }
        }

        $wallMs = (int) round((microtime(true) - $started) * 1000);
        ksort($statuses);

        return [
            'samples' => $samples,
            'completed' => count($latencies),
            'transport_errors' => $errors,
            'success' => $success,
            'success_rate' => $samples > 0 ? round($success / $samples * 100, 1) : 0.0,
            'wall_ms' => $wallMs,
            'requests_per_sec' => $wallMs > 0 ? round($samples / ($wallMs / 1000), 1) : null,
            'status_distribution' => $statuses,
            'latency' => $this->latencyStats($latencies),
        ];
    }

    /**
     * @param  array<int,int>  $values  per-sample latencies in ms
     */
    private function latencyStats(array $values): array
    {
        if ($values === []) {
            return ['min' => null, 'avg' => null, 'p50' => null, 'p90' => null, 'p95' => null, 'p99' => null, 'max' => null];
        }

        sort($values);

        return [
            'min' => $values[0],
            'avg' => (int) round(array_sum($values) / count($values)),
            'p50' => $this->percentile($values, 50),
            'p90' => $this->percentile($values, 90),
            'p95' => $this->percentile($values, 95),
            'p99' => $this->percentile($values, 99),
            'max' => $values[count($values) - 1],
        ];
    }

    /**
     * Nearest-rank percentile over a pre-sorted array.
     */
    private function percentile(array $sorted, int $p): int
    {
        $rank = (int) ceil($p / 100 * count($sorted));
        $index = max(0, min(count($sorted) - 1, $rank - 1));

        return $sorted[$index];
    }
}
