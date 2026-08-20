<?php

namespace App\Services\Assertions;

use Illuminate\Support\Arr;

/**
 * Evaluates assertions against a response.
 *
 * A response is described by its status, elapsed time, headers, and body.
 * Each assertion names a `path` — "status", "time_ms", "header.<name>", or a
 * dot path into the decoded JSON body — an `operator` from the closed
 * vocabulary in Assertion, and (for most operators) an `expected` value.
 *
 * Evaluation never throws on bad input: an assertion that cannot be evaluated
 * fails with a reason, because a suite that explodes on one malformed row is
 * less useful than one that reports it.
 */
class AssertionEvaluator
{
    /**
     * A value that is genuinely absent, distinct from a stored null.
     */
    private const MISSING = "\0__missing__\0";

    /**
     * @param  array  $assertions  rows of {path, operator, expected?, description?}
     * @param  array  $response  {status, time_ms, headers, body}
     * @return array{passed: bool, total: int, passed_count: int, failed_count: int, results: array}
     */
    public function evaluate(array $assertions, array $response): array
    {
        $decoded = $this->decodeBody($response['body'] ?? null);
        $results = [];

        foreach ($assertions as $assertion) {
            $results[] = $this->evaluateOne(is_array($assertion) ? $assertion : [], $response, $decoded);
        }

        $passedCount = count(array_filter($results, fn ($r) => $r['passed']));

        return [
            'passed' => $passedCount === count($results),
            'total' => count($results),
            'passed_count' => $passedCount,
            'failed_count' => count($results) - $passedCount,
            'results' => $results,
        ];
    }

    private function evaluateOne(array $assertion, array $response, mixed $decoded): array
    {
        $path = (string) ($assertion['path'] ?? '');
        $operator = (string) ($assertion['operator'] ?? '');
        $expected = $assertion['expected'] ?? null;

        $result = [
            'path' => $path,
            'operator' => $operator,
            'expected' => $expected,
            'description' => $assertion['description'] ?? null,
            'actual' => null,
            'passed' => false,
            'error' => null,
        ];

        if ($path === '') {
            return ['error' => 'Assertion has no path.'] + $result;
        }

        if (! array_key_exists($operator, Assertion::OPERATORS)) {
            return ['error' => "Unknown operator: {$operator}"] + $result;
        }

        if (Assertion::needsExpected($operator) && ($expected === null || $expected === '')) {
            return ['error' => "Operator {$operator} needs an expected value."] + $result;
        }

        $actual = $this->resolve($path, $response, $decoded);
        $exists = $actual !== self::MISSING;
        $result['actual'] = $exists ? $actual : null;

        // Existence operators are the only ones that treat a missing value as
        // something other than a failure.
        if ($operator === 'exists') {
            return ['passed' => $exists] + $result;
        }

        if ($operator === 'not_exists') {
            return ['passed' => ! $exists] + $result;
        }

        if (! $exists) {
            return ['error' => 'No value at that path.'] + $result;
        }

        try {
            $result['passed'] = $this->compare($operator, $actual, $expected);
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    private function compare(string $operator, mixed $actual, mixed $expected): bool
    {
        return match ($operator) {
            // Loose on purpose: an expected value arriving as the string "200"
            // should match an integer status of 200.
            'equals' => $this->looselyEqual($actual, $expected),
            'not_equals' => ! $this->looselyEqual($actual, $expected),

            'contains' => str_contains($this->toText($actual), (string) $expected),
            'not_contains' => ! str_contains($this->toText($actual), (string) $expected),

            'matches' => $this->matches($this->toText($actual), (string) $expected),

            'greater_than' => $this->toNumber($actual) > $this->toNumber($expected),
            'greater_or_equal' => $this->toNumber($actual) >= $this->toNumber($expected),
            'less_than' => $this->toNumber($actual) < $this->toNumber($expected),
            'less_or_equal' => $this->toNumber($actual) <= $this->toNumber($expected),

            'is_type' => $this->isType($actual, strtolower(trim((string) $expected))),
            'has_length' => $this->length($actual) === (int) $this->toNumber($expected),

            default => false,
        };
    }

    /**
     * Resolve a path against the response.
     */
    private function resolve(string $path, array $response, mixed $decoded): mixed
    {
        if ($path === Assertion::STATUS_PATH) {
            return $response['status'] ?? self::MISSING;
        }

        if ($path === Assertion::TIME_PATH) {
            return $response['time_ms'] ?? self::MISSING;
        }

        if (str_starts_with($path, Assertion::HEADER_PREFIX)) {
            return $this->header($response['headers'] ?? [], substr($path, strlen(Assertion::HEADER_PREFIX)));
        }

        if (! is_array($decoded)) {
            // A non-JSON body can still be asserted on as a whole via "body".
            return $path === 'body' ? ($response['body'] ?? self::MISSING) : self::MISSING;
        }

        return Arr::get($decoded, $this->normalisePath($path), self::MISSING);
    }

    /**
     * "$.data.items[0].id" and "data.items.0.id" address the same value.
     */
    private function normalisePath(string $path): string
    {
        $path = preg_replace('/^\$\.?/', '', $path);

        return str_replace(['[', ']'], ['.', ''], $path);
    }

    /**
     * Headers arrive either as "Name: value" or "Name: [values]", and header
     * names are case-insensitive.
     */
    private function header(array $headers, string $name): mixed
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return is_array($value) ? implode(', ', $value) : $value;
            }
        }

        return self::MISSING;
    }

    private function decodeBody(mixed $body): mixed
    {
        if (is_array($body)) {
            return $body;
        }

        if (! is_string($body) || trim($body) === '') {
            return null;
        }

        return json_decode($body, true);
    }

    private function looselyEqual(mixed $actual, mixed $expected): bool
    {
        if (is_bool($actual)) {
            return $actual === filter_var($expected, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual === (float) $expected;
        }

        if (is_array($actual)) {
            return json_encode($actual) === json_encode(
                is_string($expected) ? json_decode($expected, true) : $expected
            );
        }

        return (string) $actual === (string) $expected;
    }

    private function matches(string $subject, string $pattern): bool
    {
        // Accept both "/foo/i" and a bare "foo".
        $delimited = (bool) preg_match('/^([\/#~]).*\1[imsuxADSUXJn]*$/', $pattern);
        $final = $delimited ? $pattern : '/'.str_replace('/', '\/', $pattern).'/';

        $result = @preg_match($final, $subject);

        if ($result === false) {
            throw new \RuntimeException('Invalid regular expression.');
        }

        return $result === 1;
    }

    private function toText(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return (string) json_encode($value);
        }

        return (string) $value;
    }

    private function toNumber(mixed $value): float
    {
        if (! is_numeric($value)) {
            throw new \RuntimeException('Value is not numeric.');
        }

        return (float) $value;
    }

    private function isType(mixed $value, string $type): bool
    {
        if (! in_array($type, Assertion::TYPES, true)) {
            throw new \RuntimeException('Unknown type: '.$type);
        }

        return match ($type) {
            'string' => is_string($value),
            // JSON has one number type; a numeric string is not a number.
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            // A JSON object decodes to an associative array; a JSON array to a list.
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ! array_is_list($value),
            'null' => $value === null,
        };
    }

    private function length(mixed $value): int
    {
        if (is_array($value)) {
            return count($value);
        }

        if (is_string($value)) {
            return mb_strlen($value);
        }

        throw new \RuntimeException('Length applies to strings and arrays.');
    }
}
