<?php

namespace App\Services\Contracts;

/**
 * Infers a compact JSON-Schema-like description from a decoded JSON value.
 *
 * The point of the contract feature: you never write the schema. A known-good
 * response is walked into a structural description — types, object properties,
 * which fields were always present (required), array element shape — and that
 * becomes the endpoint's contract. Later responses are checked against it, so
 * a silently dropped field or a number that became a string is caught even
 * when the status stays 200.
 *
 * Deliberately small: enough structure to catch real breaking changes, not a
 * full JSON Schema implementation.
 */
class SchemaInferrer
{
    public function infer(mixed $value): array
    {
        return $this->describe($value);
    }

    /**
     * Infer one schema from many samples of the same thing — e.g. every
     * observed argument set for a tool. Merging relaxes fields that were not
     * always present, so the result reflects what is really always sent.
     */
    public function inferMany(array $samples): ?array
    {
        $schema = null;
        foreach ($samples as $sample) {
            $one = $this->describe($sample);
            $schema = $schema === null ? $one : $this->merge($schema, $one);
        }

        return $schema;
    }

    /**
     * Infer from a raw response body string, returning null when it is not
     * JSON (a contract only makes sense over structured data).
     */
    public function fromBody(?string $body): ?array
    {
        if ($body === null || trim($body) === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        if ($decoded === null && strtolower(trim($body)) !== 'null') {
            return null;
        }

        return $this->describe($decoded);
    }

    private function describe(mixed $value): array
    {
        return match (true) {
            is_null($value) => ['type' => 'null'],
            is_bool($value) => ['type' => 'boolean'],
            is_int($value) => ['type' => 'integer'],
            is_float($value) => ['type' => 'number'],
            is_string($value) => $this->describeString($value),
            is_array($value) && array_is_list($value) => $this->describeArray($value),
            is_array($value) => $this->describeObject($value),
            default => ['type' => 'unknown'],
        };
    }

    private function describeString(string $value): array
    {
        $schema = ['type' => 'string'];

        // A recognisable format is worth catching — a date field that stops
        // being a date is a real break.
        if ($format = $this->detectFormat($value)) {
            $schema['format'] = $format;
        }

        return $schema;
    }

    private function describeArray(array $list): array
    {
        if ($list === []) {
            return ['type' => 'array', 'items' => ['type' => 'unknown']];
        }

        // Merge the element schemas so an array of objects yields one merged
        // item schema rather than just the first element's.
        $merged = $this->describe($list[0]);
        foreach (array_slice($list, 1) as $element) {
            $merged = $this->merge($merged, $this->describe($element));
        }

        return ['type' => 'array', 'items' => $merged];
    }

    private function describeObject(array $object): array
    {
        $properties = [];
        foreach ($object as $key => $val) {
            $properties[(string) $key] = $this->describe($val);
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            // Everything present in this exemplar is required; merging across
            // array elements later relaxes fields that are not always there.
            'required' => array_map('strval', array_keys($object)),
        ];
    }

    /**
     * Combine two schemas for the same position (used across array elements).
     * Types widen to a set; object required narrows to the intersection.
     */
    private function merge(array $a, array $b): array
    {
        if ($a === $b) {
            return $a;
        }

        $typeA = (array) ($a['type'] ?? 'unknown');
        $typeB = (array) ($b['type'] ?? 'unknown');
        $types = array_values(array_unique(array_merge($typeA, $typeB)));

        // integer + number collapse to number — both are JSON numbers.
        if (in_array('integer', $types, true) && in_array('number', $types, true)) {
            $types = array_values(array_diff($types, ['integer']));
        }

        $merged = ['type' => count($types) === 1 ? $types[0] : $types];

        if (isset($a['properties']) || isset($b['properties'])) {
            $propsA = $a['properties'] ?? [];
            $propsB = $b['properties'] ?? [];
            $keys = array_unique(array_merge(array_keys($propsA), array_keys($propsB)));

            $properties = [];
            foreach ($keys as $key) {
                $properties[$key] = isset($propsA[$key], $propsB[$key])
                    ? $this->merge($propsA[$key], $propsB[$key])
                    : ($propsA[$key] ?? $propsB[$key]);
            }
            $merged['properties'] = $properties;
            $merged['required'] = array_values(array_intersect(
                $a['required'] ?? [],
                $b['required'] ?? []
            ));
        }

        if (isset($a['items']) || isset($b['items'])) {
            $merged['items'] = isset($a['items'], $b['items'])
                ? $this->merge($a['items'], $b['items'])
                : ($a['items'] ?? $b['items']);
        }

        return $merged;
    }

    private function detectFormat(string $value): ?string
    {
        return match (true) {
            (bool) preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2})/', $value) => 'date-time',
            (bool) preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $value) => 'email',
            (bool) preg_match('#^https?://#i', $value) => 'uri',
            (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) => 'uuid',
            default => null,
        };
    }
}
