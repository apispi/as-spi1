<?php

namespace App\Services\Fuzz;

use App\Services\Collections\RequestExecutor;

/**
 * Sends each generated variant to the endpoint and classifies the response.
 *
 * The verdict per variant:
 *  - server_error   5xx — the endpoint crashed on bad input (a real finding)
 *  - accepted_invalid  2xx on a variant a good API should reject (a finding —
 *                      the endpoint took garbage without complaint)
 *  - rejected       4xx — handled gracefully (good; expected for bad input)
 *  - ok             2xx on a variant that should be accepted (baseline etc.)
 *  - error          transport failure / SSRF refusal
 *
 * Uses the shared RequestExecutor, so every fuzz request gets the same SSRF
 * validation and pinning as any other outbound call.
 */
class FuzzRunner
{
    public function __construct(
        private readonly FuzzGenerator $generator,
        private readonly RequestExecutor $executor,
    ) {
    }

    /**
     * @param  array  $request  resolved {method,url,headers,body}
     */
    public function run(array $request): array
    {
        $decoded = json_decode((string) ($request['body'] ?? ''), true);
        $variants = $this->generator->generate($decoded);

        $results = [];
        $findings = 0;

        foreach ($variants as $variant) {
            $response = $this->executor->send([
                'protocol' => 'rest',
                'method' => strtoupper($request['method'] ?? 'POST'),
                'url' => $request['url'],
                'headers' => ($request['headers'] ?? []) + ['Content-Type' => 'application/json'],
                'body' => json_encode($variant['body']),
            ]);

            $verdict = $this->classify($variant, $response);
            if (in_array($verdict, ['server_error', 'accepted_invalid'], true)) {
                $findings++;
            }

            $results[] = [
                'label' => $variant['label'],
                'expects_reject' => $variant['expects_reject'],
                'status' => $response['status'],
                'verdict' => $verdict,
            ];
        }

        return [
            'passed' => $findings === 0,
            'total' => count($results),
            'findings' => $findings,
            'server_errors' => count(array_filter($results, fn ($r) => $r['verdict'] === 'server_error')),
            'accepted_invalid' => count(array_filter($results, fn ($r) => $r['verdict'] === 'accepted_invalid')),
            'results' => $results,
        ];
    }

    private function classify(array $variant, array $response): string
    {
        if (! $response['ok'] || $response['status'] === null) {
            return 'error';
        }

        $status = (int) $response['status'];

        if ($status >= 500) {
            return 'server_error';
        }

        if ($status >= 400) {
            return 'rejected';
        }

        // 2xx/3xx: only a problem if the variant should have been rejected.
        return $variant['expects_reject'] ? 'accepted_invalid' : 'ok';
    }
}
