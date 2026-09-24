<?php

namespace App\Services\Diff;

use App\Services\Graphql\GraphqlIntrospector;
use App\Services\Import\ImportException;

/**
 * Compares two GraphQL schemas and classifies what changed, from the point of
 * view of a client already issuing queries against the old one.
 *
 * GraphQL has no status codes to hide behind: a removed field is a query that
 * stops parsing. That makes the breaking set both larger and sharper than
 * OpenAPI's — every field, argument, input field, enum member and union member
 * is something somebody may have written into a document.
 *
 * Nullability is the subtle part, and it cuts opposite ways depending on
 * direction. Tightening an *output* field (String to String!) only promises a
 * client more than before; loosening one (String! to String) can hand it a null
 * it never had to handle. For *inputs* it is the reverse: a newly non-null
 * argument rejects requests that used to be accepted.
 */
class GraphqlSchemaDiffer
{
    public const SEVERITY_BREAKING = 'breaking';

    public const SEVERITY_NON_BREAKING = 'non_breaking';

    public const SEVERITY_INFO = 'info';

    /** Types every schema has; changes to them are noise, not news. */
    private const BUILT_IN_SCALARS = ['String', 'Int', 'Float', 'Boolean', 'ID'];

    public function __construct(private readonly GraphqlIntrospector $introspector)
    {
    }

    /**
     * @param  string|array  $old  an introspection document (JSON) or decoded schema
     * @param  string|array  $new
     * @return array{
     *   breaking: bool, breaking_count: int, non_breaking_count: int, info_count: int,
     *   type_count: int, summary: string,
     *   changes: array<int, array{severity: string, category: string, location: ?string, detail: string}>
     * }
     */
    public function diff(string|array $old, string|array $new): array
    {
        $oldTypes = $this->types($this->load($old, 'old'));
        $newTypes = $this->types($this->load($new, 'new'));

        $changes = [];

        foreach ($oldTypes as $name => $type) {
            if (! isset($newTypes[$name])) {
                $changes[] = $this->change(self::SEVERITY_BREAKING, 'type_removed', $name, 'Type removed');

                continue;
            }

            $changes = array_merge($changes, $this->compareType($name, $type, $newTypes[$name]));
        }

        foreach ($newTypes as $name => $type) {
            if (! isset($oldTypes[$name])) {
                $changes[] = $this->change(self::SEVERITY_INFO, 'type_added', $name, 'New '.strtolower($type['kind']).' type');
            }
        }

        $order = [self::SEVERITY_BREAKING => 0, self::SEVERITY_NON_BREAKING => 1, self::SEVERITY_INFO => 2];
        usort($changes, fn ($a, $b) => [$order[$a['severity']], $a['location'] ?? ''] <=> [$order[$b['severity']], $b['location'] ?? '']);

        $counts = array_count_values(array_column($changes, 'severity'));
        $breaking = $counts[self::SEVERITY_BREAKING] ?? 0;
        $nonBreaking = $counts[self::SEVERITY_NON_BREAKING] ?? 0;
        $info = $counts[self::SEVERITY_INFO] ?? 0;

        return [
            'breaking' => $breaking > 0,
            'breaking_count' => $breaking,
            'non_breaking_count' => $nonBreaking,
            'info_count' => $info,
            'type_count' => count($newTypes),
            'summary' => sprintf('%d breaking, %d non-breaking, %d informational', $breaking, $nonBreaking, $info),
            'changes' => array_values($changes),
        ];
    }

    /**
     * @return array<int, array>
     */
    private function compareType(string $name, array $old, array $new): array
    {
        if ($old['kind'] !== $new['kind']) {
            // An object that became an interface (or a union) invalidates every
            // selection written against it; nothing below is worth reporting.
            return [$this->change(
                self::SEVERITY_BREAKING,
                'type_kind_changed',
                $name,
                'Changed from '.strtolower($old['kind']).' to '.strtolower($new['kind'])
            )];
        }

        return array_merge(
            $this->compareFields($name, $old['fields'], $new['fields']),
            $this->compareInputFields($name, $old['inputFields'], $new['inputFields']),
            $this->compareEnumValues($name, $old['enumValues'], $new['enumValues']),
            $this->comparePossibleTypes($name, $old['possibleTypes'], $new['possibleTypes']),
        );
    }

    /** Output fields: what a client selects. */
    private function compareFields(string $type, array $old, array $new): array
    {
        $changes = [];

        foreach ($old as $field => $definition) {
            $location = $type.'.'.$field;

            if (! isset($new[$field])) {
                $changes[] = $this->change(self::SEVERITY_BREAKING, 'field_removed', $location, 'Field removed');

                continue;
            }

            $changes = array_merge(
                $changes,
                $this->compareOutputType($location, $definition['type'], $new[$field]['type']),
                $this->compareArguments($location, $definition['args'], $new[$field]['args']),
            );
        }

        foreach ($new as $field => $definition) {
            if (! isset($old[$field])) {
                $changes[] = $this->change(self::SEVERITY_INFO, 'field_added', $type.'.'.$field, 'New field '.$definition['type']);
            }
        }

        return $changes;
    }

    private function compareOutputType(string $location, string $old, string $new): array
    {
        if ($old === $new) {
            return [];
        }

        $detail = 'Type changed from '.$old.' to '.$new;

        if (! $this->differsOnlyInNullability($old, $new)) {
            return [$this->change(self::SEVERITY_BREAKING, 'field_type_changed', $location, $detail)];
        }

        // Output: stricter is a stronger promise, looser can return a null the
        // client never had to handle.
        return [$this->isStricter($old, $new)
            ? $this->change(self::SEVERITY_NON_BREAKING, 'field_type_tightened', $location, $detail.' (now always present)')
            : $this->change(self::SEVERITY_BREAKING, 'field_type_loosened', $location, $detail.' (may now be null)')];
    }

    /** Arguments: what a client sends. */
    private function compareArguments(string $location, array $old, array $new): array
    {
        $changes = [];

        foreach ($old as $arg => $definition) {
            $where = $location.'('.$arg.':)';

            if (! isset($new[$arg])) {
                $changes[] = $this->change(self::SEVERITY_BREAKING, 'argument_removed', $where, 'Argument removed');

                continue;
            }

            $changes = array_merge($changes, $this->compareInputType($where, 'argument', $definition['type'], $new[$arg]['type']));
        }

        foreach ($new as $arg => $definition) {
            if (isset($old[$arg])) {
                continue;
            }

            $where = $location.'('.$arg.':)';
            $changes[] = $this->isRequired($definition)
                ? $this->change(self::SEVERITY_BREAKING, 'required_argument_added', $where, 'New required argument '.$definition['type'])
                : $this->change(self::SEVERITY_NON_BREAKING, 'optional_argument_added', $where, 'New optional argument '.$definition['type']);
        }

        return $changes;
    }

    /** Input object fields: the same rules as arguments. */
    private function compareInputFields(string $type, array $old, array $new): array
    {
        $changes = [];

        foreach ($old as $field => $definition) {
            $location = $type.'.'.$field;

            if (! isset($new[$field])) {
                $changes[] = $this->change(self::SEVERITY_BREAKING, 'input_field_removed', $location, 'Input field removed');

                continue;
            }

            $changes = array_merge($changes, $this->compareInputType($location, 'input field', $definition['type'], $new[$field]['type']));
        }

        foreach ($new as $field => $definition) {
            if (isset($old[$field])) {
                continue;
            }

            $location = $type.'.'.$field;
            $changes[] = $this->isRequired($definition)
                ? $this->change(self::SEVERITY_BREAKING, 'required_input_field_added', $location, 'New required input field '.$definition['type'])
                : $this->change(self::SEVERITY_NON_BREAKING, 'optional_input_field_added', $location, 'New optional input field '.$definition['type']);
        }

        return $changes;
    }

    private function compareInputType(string $location, string $noun, string $old, string $new): array
    {
        if ($old === $new) {
            return [];
        }

        $detail = ucfirst($noun).' type changed from '.$old.' to '.$new;

        if (! $this->differsOnlyInNullability($old, $new)) {
            return [$this->change(self::SEVERITY_BREAKING, 'input_type_changed', $location, $detail)];
        }

        // Input: stricter rejects requests that used to be accepted.
        return [$this->isStricter($old, $new)
            ? $this->change(self::SEVERITY_BREAKING, 'input_now_required', $location, $detail.' (now required)')
            : $this->change(self::SEVERITY_NON_BREAKING, 'input_now_optional', $location, $detail.' (now optional)')];
    }

    private function compareEnumValues(string $type, array $old, array $new): array
    {
        $changes = [];

        foreach (array_diff($old, $new) as $value) {
            // A removed member breaks anyone sending it, and anyone with a
            // total switch over the enum.
            $changes[] = $this->change(self::SEVERITY_BREAKING, 'enum_value_removed', $type, 'Enum value '.$value.' removed');
        }

        foreach (array_diff($new, $old) as $value) {
            $changes[] = $this->change(self::SEVERITY_NON_BREAKING, 'enum_value_added', $type, 'Enum value '.$value.' added');
        }

        return $changes;
    }

    private function comparePossibleTypes(string $type, array $old, array $new): array
    {
        $changes = [];

        foreach (array_diff($old, $new) as $member) {
            $changes[] = $this->change(self::SEVERITY_BREAKING, 'union_member_removed', $type, $member.' is no longer a member');
        }

        foreach (array_diff($new, $old) as $member) {
            $changes[] = $this->change(self::SEVERITY_NON_BREAKING, 'union_member_added', $type, $member.' added as a member');
        }

        return $changes;
    }

    /** Required means non-null with nothing to fall back on. */
    private function isRequired(array $definition): bool
    {
        return str_ends_with($definition['type'], '!') && $definition['default'] === null;
    }

    private function differsOnlyInNullability(string $a, string $b): bool
    {
        return str_replace('!', '', $a) === str_replace('!', '', $b);
    }

    private function isStricter(string $old, string $new): bool
    {
        return substr_count($new, '!') > substr_count($old, '!');
    }

    /**
     * Flatten a schema into types keyed by name, dropping the introspection
     * meta-types and built-in scalars nobody can change.
     *
     * @return array<string, array>
     */
    private function types(array $schema): array
    {
        $types = [];

        foreach ($schema['types'] ?? [] as $type) {
            $name = $type['name'] ?? null;

            if (! is_string($name) || str_starts_with($name, '__') || in_array($name, self::BUILT_IN_SCALARS, true)) {
                continue;
            }

            $types[$name] = [
                'kind' => (string) ($type['kind'] ?? 'OBJECT'),
                'fields' => $this->fields($type['fields'] ?? []),
                'inputFields' => $this->inputFields($type['inputFields'] ?? []),
                'enumValues' => array_values(array_filter(array_map(
                    fn ($v) => is_array($v) ? ($v['name'] ?? null) : null,
                    $type['enumValues'] ?? []
                ))),
                'possibleTypes' => array_values(array_filter(array_map(
                    fn ($v) => is_array($v) ? ($v['name'] ?? null) : null,
                    $type['possibleTypes'] ?? []
                ))),
            ];
        }

        return $types;
    }

    private function fields(mixed $fields): array
    {
        $out = [];

        foreach (is_array($fields) ? $fields : [] as $field) {
            if (! is_array($field) || ! isset($field['name'])) {
                continue;
            }

            $out[(string) $field['name']] = [
                'type' => $this->introspector->signature($field['type'] ?? []),
                'args' => $this->inputFields($field['args'] ?? []),
            ];
        }

        return $out;
    }

    /** Arguments and input-object fields share a shape. */
    private function inputFields(mixed $fields): array
    {
        $out = [];

        foreach (is_array($fields) ? $fields : [] as $field) {
            if (! is_array($field) || ! isset($field['name'])) {
                continue;
            }

            $out[(string) $field['name']] = [
                'type' => $this->introspector->signature($field['type'] ?? []),
                'default' => $field['defaultValue'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Accept whatever a user is likely to have to hand: a full introspection
     * response, the `data` object, or the `__schema` object itself.
     */
    private function load(string|array $document, string $which): array
    {
        $decoded = is_array($document) ? $document : $this->decode($document, $which);

        $schema = $decoded['data']['__schema']
            ?? $decoded['__schema']
            ?? $decoded;

        if (! is_array($schema) || ! isset($schema['types']) || ! is_array($schema['types'])) {
            throw new ImportException(
                "The {$which} document is not a GraphQL introspection result (no __schema.types). ".
                'Run introspection on the endpoint and paste the JSON it returns.'
            );
        }

        return $schema;
    }

    private function decode(string $document, string $which): array
    {
        $document = trim($document);

        if ($document === '') {
            throw new ImportException("The {$which} document is empty.");
        }

        $decoded = json_decode($document, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ImportException("Invalid JSON in the {$which} document: ".json_last_error_msg());
        }

        if (! is_array($decoded)) {
            throw new ImportException("The {$which} document did not parse into an object.");
        }

        return $decoded;
    }

    private function change(string $severity, string $category, ?string $location, string $detail): array
    {
        return compact('severity', 'category', 'location', 'detail');
    }
}
