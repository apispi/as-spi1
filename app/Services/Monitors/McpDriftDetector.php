<?php

namespace App\Services\Monitors;

use App\Services\Mcp\McpClient;

/**
 * Detects shape changes in an MCP server's tool surface.
 *
 * A snapshot is the normalised {name → description+schema} map from
 * tools/list. Comparing this run's snapshot to the previous one answers the
 * question nobody else asks: "did the server my agent depends on quietly
 * change its contract?" — a tool removed, a schema changed, a description
 * rewritten (which matters, because agents read descriptions as instructions).
 *
 * Values are hashed rather than stored verbatim in the comparison, but the
 * full snapshot is kept in the result so a drift report can show WHAT changed.
 */
class McpDriftDetector
{
    /** @var (callable(string): McpClient)|null test seam */
    public function __construct(private $clientFactory = null)
    {
    }

    /**
     * @return array{snapshot: array, tools: int}
     */
    public function snapshot(string $url): array
    {
        $client = $this->clientFactory ? ($this->clientFactory)($url) : new McpClient($url);
        $client->initialize();

        $tools = $client->listTools()['tools'] ?? [];

        $snapshot = [];
        foreach ($tools as $tool) {
            $name = $tool['name'] ?? null;
            if (! is_string($name) || $name === '') {
                continue;
            }

            $snapshot[$name] = [
                'description_hash' => sha1((string) ($tool['description'] ?? '')),
                // Canonical JSON so key order cannot masquerade as drift.
                'schema_hash' => sha1($this->canonical($tool['inputSchema'] ?? null)),
            ];
        }

        ksort($snapshot);

        return ['snapshot' => $snapshot, 'tools' => count($snapshot)];
    }

    /**
     * @return array{drifted: bool, added: array, removed: array, changed: array}
     */
    public function compare(array $previous, array $current): array
    {
        $added = array_values(array_diff(array_keys($current), array_keys($previous)));
        $removed = array_values(array_diff(array_keys($previous), array_keys($current)));

        $changed = [];
        foreach (array_intersect_key($current, $previous) as $name => $entry) {
            $before = $previous[$name];
            $kinds = [];

            if ($entry['schema_hash'] !== ($before['schema_hash'] ?? null)) {
                $kinds[] = 'schema';
            }
            if ($entry['description_hash'] !== ($before['description_hash'] ?? null)) {
                $kinds[] = 'description';
            }

            if ($kinds !== []) {
                $changed[] = ['tool' => $name, 'what' => $kinds];
            }
        }

        return [
            'drifted' => $added !== [] || $removed !== [] || $changed !== [],
            'added' => $added,
            'removed' => $removed,
            'changed' => $changed,
        ];
    }

    public function describe(array $diff): string
    {
        $parts = [];
        if ($diff['removed'] !== []) {
            $parts[] = 'removed: '.implode(', ', $diff['removed']);
        }
        if ($diff['added'] !== []) {
            $parts[] = 'added: '.implode(', ', $diff['added']);
        }
        foreach ($diff['changed'] as $change) {
            $parts[] = $change['tool'].' changed ('.implode('+', $change['what']).')';
        }

        return $parts === [] ? 'No drift.' : 'Drift — '.implode('; ', $parts);
    }

    private function canonical(mixed $value): string
    {
        if (is_array($value)) {
            $isList = array_is_list($value);
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = json_decode($this->canonical($v), true);
            }
            if (! $isList) {
                ksort($out);
            }

            return json_encode($out);
        }

        return json_encode($value);
    }
}
