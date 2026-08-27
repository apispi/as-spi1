<?php

namespace App\Services\Contracts;

/**
 * Validates a live response against a stored contract schema and reports how
 * it diverged.
 *
 * Three kinds of change, only the first two of which break a consumer:
 *  - removed: a required field the contract had is gone            (breaking)
 *  - type_changed: a field's type is no longer what the contract   (breaking)
 *  - added: a field the contract never had has appeared            (additive)
 *
 * Everything is reported with a dotted path so a drift report can point at the
 * exact field.
 */
class ContractChecker
{
    private array $removed = [];

    private array $typeChanged = [];

    private array $added = [];

    /**
     * @param  array  $contract  a schema from SchemaInferrer
     * @param  mixed  $actual    the decoded live response value
     * @return array{conforms: bool, breaking: bool, removed: array, type_changed: array, added: array}
     */
    public function check(array $contract, mixed $actual): array
    {
        $this->removed = [];
        $this->typeChanged = [];
        $this->added = [];

        $this->walk($contract, $actual, '$');

        $breaking = $this->removed !== [] || $this->typeChanged !== [];

        return [
            'conforms' => ! $breaking && $this->added === [],
            'breaking' => $breaking,
            'removed' => $this->removed,
            'type_changed' => $this->typeChanged,
            'added' => $this->added,
        ];
    }

    public function fromBody(array $contract, ?string $body): array
    {
        $actual = $body === null ? null : json_decode($body, true);

        return $this->check($contract, $actual);
    }

    private function walk(array $schema, mixed $actual, string $path): void
    {
        $expected = (array) ($schema['type'] ?? 'unknown');
        $actualType = $this->typeOf($actual);

        if (! $this->typeMatches($expected, $actualType)) {
            $this->typeChanged[] = [
                'path' => $path,
                'expected' => implode('|', $expected),
                'actual' => $actualType,
            ];

            // A type mismatch at this node makes deeper comparison meaningless.
            return;
        }

        if (in_array('object', $expected, true) && is_array($actual)) {
            $this->walkObject($schema, $actual, $path);
        }

        if (in_array('array', $expected, true) && is_array($actual) && isset($schema['items'])) {
            // Check every element against the item schema; report each element
            // path so a break in one row is locatable.
            foreach ($actual as $i => $element) {
                $this->walk($schema['items'], $element, $path.'['.$i.']');
            }
        }
    }

    private function walkObject(array $schema, array $actual, string $path): void
    {
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];

        foreach ($properties as $key => $propSchema) {
            $childPath = $path.'.'.$key;

            if (! array_key_exists($key, $actual)) {
                if (in_array($key, $required, true)) {
                    $this->removed[] = ['path' => $childPath];
                }

                continue;
            }

            $this->walk($propSchema, $actual[$key], $childPath);
        }

        // Fields present now that the contract never described.
        foreach ($actual as $key => $value) {
            if (! array_key_exists((string) $key, $properties)) {
                $this->added[] = ['path' => $path.'.'.$key, 'type' => $this->typeOf($value)];
            }
        }
    }

    private function typeOf(mixed $value): string
    {
        return match (true) {
            is_null($value) => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) && array_is_list($value) => 'array',
            is_array($value) => 'object',
            default => 'unknown',
        };
    }

    /**
     * integer satisfies number; null is only ever compatible with null.
     */
    private function typeMatches(array $expected, string $actual): bool
    {
        if (in_array($actual, $expected, true)) {
            return true;
        }

        if ($actual === 'integer' && in_array('number', $expected, true)) {
            return true;
        }

        return false;
    }
}
