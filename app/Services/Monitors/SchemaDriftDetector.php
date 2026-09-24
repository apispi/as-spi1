<?php

namespace App\Services\Monitors;

use App\Services\Collections\RequestExecutor;
use App\Services\Diff\GraphqlSchemaDiffer;
use App\Services\Diff\OpenApiDiffer;
use App\Services\Graphql\GraphqlIntrospector;
use App\Services\Import\ImportException;
use RuntimeException;

/**
 * Watches an API's published schema and reports what changed since last time,
 * classified as breaking or not.
 *
 * The API Diff screen answers that question once, when somebody thinks to ask.
 * This asks it every hour, about an API you do not control — which is the case
 * where nobody thinks to ask until something is already broken.
 *
 * Unlike MCP drift, which can only say "something changed", this knows the
 * difference between a field being added and a field being removed. Only the
 * breaking kind fails the monitor; an additive change is recorded and passed,
 * because paging somebody at 3am because a third party added an optional
 * argument is how monitoring gets muted.
 */
class SchemaDriftDetector
{
    public const FLAVOUR_GRAPHQL = 'graphql';

    public const FLAVOUR_OPENAPI = 'openapi';

    /**
     * Past this, the document is not kept as a baseline. Change is still
     * detected by hash; only the field-level diff is given up, which beats
     * storing a megabyte of schema on every run of every monitor.
     */
    public const MAX_BASELINE_BYTES = 1048576;

    public function __construct(
        private readonly RequestExecutor $executor,
        private readonly GraphqlSchemaDiffer $graphql,
        private readonly OpenApiDiffer $openapi,
    ) {
    }

    /**
     * Fetch the current schema.
     *
     * @return array{document: ?array, raw: string, hash: string, size: int}
     */
    public function fetch(string $url, string $flavour): array
    {
        $raw = $flavour === self::FLAVOUR_GRAPHQL
            ? $this->fetchGraphql($url)
            : $this->fetchOpenapi($url);

        $size = strlen($raw);

        return [
            // Decoded and kept only when small enough to be worth a baseline.
            'document' => $size <= self::MAX_BASELINE_BYTES ? json_decode($raw, true) : null,
            'raw' => $raw,
            'hash' => hash('sha256', $raw),
            'size' => $size,
        ];
    }

    /**
     * Compare a stored baseline against what was just fetched.
     *
     * @return array{
     *   breaking: bool, changed: bool, breaking_count: int, non_breaking_count: int,
     *   info_count: int, summary: string, changes: array
     * }
     */
    public function compare(array $previous, array $current, string $flavour): array
    {
        try {
            $result = $flavour === self::FLAVOUR_GRAPHQL
                ? $this->graphql->diff($previous, $current)
                : $this->openapi->diff(json_encode($previous), json_encode($current));
        } catch (ImportException $e) {
            // A baseline that no longer parses is a change worth reporting,
            // not a crash — the endpoint may now be serving something else
            // entirely.
            return [
                'breaking' => true,
                'changed' => true,
                'breaking_count' => 1,
                'non_breaking_count' => 0,
                'info_count' => 0,
                'summary' => 'Could not compare: '.$e->getMessage(),
                'changes' => [],
            ];
        }

        return [
            'breaking' => $result['breaking'],
            'changed' => $result['changes'] !== [],
            'breaking_count' => $result['breaking_count'],
            'non_breaking_count' => $result['non_breaking_count'],
            'info_count' => $result['info_count'],
            'summary' => $this->describe($result),
            'changes' => $result['changes'],
        ];
    }

    /** One line for the alert and the results list. */
    public function describe(array $result): string
    {
        if ($result['changes'] === []) {
            return 'No schema change.';
        }

        $parts = [];
        if ($result['breaking_count'] > 0) {
            $parts[] = $result['breaking_count'].' breaking';
        }
        if ($result['non_breaking_count'] > 0) {
            $parts[] = $result['non_breaking_count'].' non-breaking';
        }
        if ($result['info_count'] > 0) {
            $parts[] = $result['info_count'].' informational';
        }

        $lead = $result['breaking_count'] > 0 ? 'Breaking schema change' : 'Schema changed';

        return $lead.' — '.implode(', ', $parts).'. '
            .($result['changes'][0]['detail'] ?? '')
            .(isset($result['changes'][0]['location']) ? ' ('.$result['changes'][0]['location'].')' : '')
            .(isset($result['changes'][0]['operation']) ? ' ('.$result['changes'][0]['operation'].')' : '');
    }

    /** Run the diff-grade introspection query and keep just the schema. */
    private function fetchGraphql(string $url): string
    {
        $response = $this->executor->send([
            'protocol' => 'rest',
            'method' => 'POST',
            'url' => $url,
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body' => json_encode(['query' => GraphqlIntrospector::DIFF_QUERY]),
        ]);

        $this->assertReachable($response, $url);

        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        $schema = $decoded['data']['__schema'] ?? null;

        if (! is_array($schema)) {
            throw new RuntimeException(
                isset($decoded['errors'][0]['message'])
                    ? 'Introspection failed: '.$decoded['errors'][0]['message']
                    : 'No schema returned. Introspection may be disabled.'
            );
        }

        // Canonical ordering, so a server that shuffles its type list between
        // responses does not look like a change every single run.
        return json_encode($this->canonicalise($schema));
    }

    private function fetchOpenapi(string $url): string
    {
        $response = $this->executor->send([
            'protocol' => 'rest',
            'method' => 'GET',
            'url' => $url,
            'headers' => ['Accept' => 'application/json'],
        ]);

        $this->assertReachable($response, $url);

        $body = (string) ($response['body'] ?? '');
        $decoded = json_decode($body, true);

        if (! is_array($decoded) || ! isset($decoded['openapi'])) {
            throw new RuntimeException('That URL did not return an OpenAPI 3 document.');
        }

        return json_encode($this->canonicalise($decoded));
    }

    private function assertReachable(array $response, string $url): void
    {
        if (! ($response['ok'] ?? false)) {
            throw new RuntimeException($response['error'] ?? 'Could not reach '.$url);
        }

        if (($response['status'] ?? 0) >= 400) {
            throw new RuntimeException('Returned HTTP '.$response['status'].'.');
        }
    }

    /** Sort object keys throughout, leaving list order alone. */
    private function canonicalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $out = array_map(fn ($v) => $this->canonicalise($v), $value);

        if (! array_is_list($out)) {
            ksort($out);
        }

        return $out;
    }
}
