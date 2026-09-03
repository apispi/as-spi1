<?php

namespace App\Services\Snapshots;

/**
 * Compares a saved "golden" response against a fresh one and reports how their
 * VALUES have changed over time.
 *
 * This is the complement to the contract/parity engines. A contract catches
 * shape drift (a removed field, a number that became a string); parity catches
 * shape divergence between two environments and deliberately ignores values.
 * A snapshot is the same endpoint over time, so a changed value IS the signal —
 * an id that shifted, a total that moved, a flag that flipped — even when the
 * shape is identical and the status stays 200.
 *
 * Deliberately value-oriented: it walks both decoded bodies in parallel and
 * records changed / added / removed leaf paths, plus a status change.
 */
class SnapshotDiffer
{
    /** Cap on reported leaf differences, so a wholesale change stays readable. */
    public const MAX_DIFFS = 200;

    /**
     * @param  array{status?:int|null, body?:mixed}  $golden  the captured baseline
     * @param  array{status?:int|null, body?:mixed}  $actual  the fresh response
     */
    public function compare(array $golden, array $actual): array
    {
        $statusA = $golden['status'] ?? null;
        $statusB = $actual['status'] ?? null;
        $statusChanged = $statusA !== $statusB;

        $goldenBody = $this->decode($golden['body'] ?? null);
        $actualBody = $this->decode($actual['body'] ?? null);

        $changed = [];
        $added = [];
        $removed = [];

        if ($this->isJson($goldenBody) || $this->isJson($actualBody)) {
            $this->walk('$', $goldenBody, $actualBody, $changed, $added, $removed);
            $bodyMatches = $changed === [] && $added === [] && $removed === [];
        } else {
            // Non-JSON: compare the raw strings whole.
            $a = (string) ($golden['body'] ?? '');
            $b = (string) ($actual['body'] ?? '');
            $bodyMatches = $a === $b;
            if (! $bodyMatches) {
                $changed[] = [
                    'path' => '$',
                    'from' => $this->preview($a),
                    'to' => $this->preview($b),
                ];
            }
        }

        $truncated = count($changed) > self::MAX_DIFFS
            || count($added) > self::MAX_DIFFS
            || count($removed) > self::MAX_DIFFS;

        $changed = array_slice($changed, 0, self::MAX_DIFFS);
        $added = array_slice($added, 0, self::MAX_DIFFS);
        $removed = array_slice($removed, 0, self::MAX_DIFFS);

        $matches = ! $statusChanged && $bodyMatches;

        return [
            'matches' => $matches,
            'status_changed' => $statusChanged,
            'status_from' => $statusA,
            'status_to' => $statusB,
            'changed' => array_values($changed),
            'added' => array_values($added),
            'removed' => array_values($removed),
            'changed_count' => count($changed),
            'added_count' => count($added),
            'removed_count' => count($removed),
            'truncated' => $truncated,
        ];
    }

    /**
     * Walk two decoded values in parallel, recording leaf-level differences.
     */
    private function walk(string $path, mixed $a, mixed $b, array &$changed, array &$added, array &$removed): void
    {
        $aIsArr = is_array($a);
        $bIsArr = is_array($b);

        // One side is a container and the other is a scalar/null → a change.
        if ($aIsArr !== $bIsArr) {
            $changed[] = ['path' => $path, 'from' => $this->preview($a), 'to' => $this->preview($b)];

            return;
        }

        if ($aIsArr && $bIsArr) {
            $keys = array_keys($a + $b);
            foreach ($keys as $key) {
                $childPath = $this->childPath($path, $key);
                $inA = array_key_exists($key, $a);
                $inB = array_key_exists($key, $b);

                if ($inA && ! $inB) {
                    $removed[] = ['path' => $childPath, 'from' => $this->preview($a[$key])];
                } elseif (! $inA && $inB) {
                    $added[] = ['path' => $childPath, 'to' => $this->preview($b[$key])];
                } else {
                    $this->walk($childPath, $a[$key], $b[$key], $changed, $added, $removed);
                }
            }

            return;
        }

        // Both scalar (or null): strict comparison so 1 !== "1" and true !== 1.
        if ($a !== $b) {
            $changed[] = ['path' => $path, 'from' => $this->preview($a), 'to' => $this->preview($b)];
        }
    }

    private function childPath(string $path, int|string $key): string
    {
        return is_int($key)
            ? $path.'['.$key.']'
            : $path.'.'.$key;
    }

    private function decode(mixed $body): mixed
    {
        if (is_array($body)) {
            return $body;
        }
        if (! is_string($body) || trim($body) === '') {
            return $body;
        }
        $decoded = json_decode($body, true);

        // json_decode returns null both for the literal `null` and for invalid
        // JSON; only treat a successful decode as structured.
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $body;
    }

    private function isJson(mixed $value): bool
    {
        return is_array($value);
    }

    /** A compact, safe representation of a value for the diff report. */
    private function preview(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            if (is_string($value) && mb_strlen($value) > 120) {
                return mb_substr($value, 0, 117).'…';
            }

            return $value;
        }

        // Container: summarise rather than embed the whole thing.
        return is_array($value) && array_is_list($value)
            ? '[array:'.count($value).']'
            : '{object:'.count((array) $value).'}';
    }
}
