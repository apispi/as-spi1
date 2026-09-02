<?php

namespace App\Services\Mcp;

use App\Models\McpProxy;
use App\Services\Agent\DestructiveHeuristic;
use App\Services\Contracts\ContractChecker;
use App\Services\Contracts\SchemaInferrer;
use Throwable;

/**
 * Replays a flight recorder's captured requests against a target MCP server and
 * diffs each new response against what was originally recorded.
 *
 * "Record production traffic once, replay it against staging to catch
 * regressions." A response whose shape changed — a field dropped, a type
 * changed — is a regression the recorded baseline makes visible, reusing the
 * contract engine to diff.
 *
 * Safe by default: a recorded tools/call for a destructive-looking tool is
 * skipped rather than re-executed, so replaying against a live server does not
 * repeat side effects. Only request-bearing JSON-RPC exchanges are replayed.
 */
class McpReplayer
{
    public function __construct(
        private readonly SchemaInferrer $inferrer = new SchemaInferrer,
        private readonly ContractChecker $checker = new ContractChecker,
    ) {
    }

    /**
     * @param  callable(string):McpClient|null  $clientFactory  test seam
     */
    public function replay(McpProxy $proxy, string $targetUrl, bool $safeMode = true, $clientFactory = null): array
    {
        $client = $clientFactory ? $clientFactory($targetUrl) : new McpClient($targetUrl);

        try {
            $client->initialize();
        } catch (Throwable $e) {
            return [
                'passed' => false, 'target' => $targetUrl, 'error' => 'Could not initialize target: '.$e->getMessage(),
                'total' => 0, 'matched' => 0, 'diverged' => 0, 'skipped' => 0, 'steps' => [],
            ];
        }

        $steps = [];
        $matched = $diverged = $skipped = 0;

        // Oldest-first, so a replay reads like the original session.
        foreach ($proxy->exchanges()->get()->reverse() as $ex) {
            $request = $ex->request ?? [];
            $method = $request['method'] ?? null;

            // Only replay real JSON-RPC calls (skip SSE opens, notifications).
            if (! is_string($method) || $method === '' || ! isset($ex->request['method'])) {
                continue;
            }

            $tool = $request['params']['name'] ?? null;

            if ($safeMode && $method === 'tools/call' && is_string($tool) && DestructiveHeuristic::isDestructive($tool)) {
                $skipped++;
                $steps[] = ['method' => $method, 'tool' => $tool, 'verdict' => 'skipped', 'note' => 'Destructive; skipped in safe mode.'];

                continue;
            }

            $steps[] = $this->replayOne($client, $ex, $method, $tool);
            $last = $steps[count($steps) - 1];
            $last['verdict'] === 'match' ? $matched++ : $diverged++;
        }

        return [
            'passed' => $diverged === 0,
            'target' => $targetUrl,
            'total' => count($steps),
            'matched' => $matched,
            'diverged' => $diverged,
            'skipped' => $skipped,
            'steps' => $steps,
        ];
    }

    private function replayOne(McpClient $client, $ex, string $method, ?string $tool): array
    {
        $recorded = $ex->response['result'] ?? null;
        $truncated = ($ex->response['truncated'] ?? false) === true;

        try {
            $message = $client->rawRequest($method, $ex->request['params'] ?? []);
        } catch (Throwable $e) {
            return ['method' => $method, 'tool' => $tool, 'verdict' => 'diverged', 'note' => 'Replay failed: '.$e->getMessage()];
        }

        if (isset($message['error'])) {
            return ['method' => $method, 'tool' => $tool, 'verdict' => 'diverged',
                'note' => 'Target returned an error: '.($message['error']['message'] ?? 'unknown')];
        }

        if ($truncated || ! is_array($recorded)) {
            return ['method' => $method, 'tool' => $tool, 'verdict' => 'match', 'note' => 'No recorded baseline to compare.'];
        }

        $diff = $this->checker->check($this->inferrer->infer($recorded), $message['result'] ?? null);

        return [
            'method' => $method,
            'tool' => $tool,
            'verdict' => $diff['breaking'] ? 'diverged' : 'match',
            'shape' => $diff['conforms'] ? null : [
                'removed' => $diff['removed'],
                'type_changed' => $diff['type_changed'],
                'added' => $diff['added'],
            ],
        ];
    }
}
