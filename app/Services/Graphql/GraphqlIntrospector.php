<?php

namespace App\Services\Graphql;

/**
 * Runs the standard GraphQL introspection query and reduces the (large) result
 * into a compact summary: the root operations (queries + mutations) with their
 * arguments and return types, and a type count. The heavy lifting is the type-
 * reference rendering — turning the nested {kind, ofType} shape back into a
 * readable signature like `[User!]!`.
 */
class GraphqlIntrospector
{
    /** A complete-enough introspection query for summarising a schema. */
    public const QUERY = <<<'GQL'
    query SpiIntrospection {
      __schema {
        queryType { name }
        mutationType { name }
        types {
          kind
          name
          fields(includeDeprecated: true) {
            name
            description
            args { name type { ...TypeRef } }
            type { ...TypeRef }
          }
        }
      }
    }
    fragment TypeRef on __Type {
      kind name
      ofType { kind name ofType { kind name ofType { kind name ofType { kind name } } } }
    }
    GQL;

    /**
     * A fuller introspection query, for comparing two schemas.
     *
     * The summary query above deliberately fetches only what the schema panel
     * shows. A diff has to see everything a client could depend on — argument
     * defaults, input object fields, enum members, union members — because
     * each of them is something whose removal breaks somebody.
     */
    public const DIFF_QUERY = <<<'GQL'
    query SpiSchemaDiff {
      __schema {
        queryType { name }
        mutationType { name }
        subscriptionType { name }
        types {
          kind
          name
          fields(includeDeprecated: true) {
            name
            type { ...TypeRef }
            args { name defaultValue type { ...TypeRef } }
          }
          inputFields { name defaultValue type { ...TypeRef } }
          enumValues(includeDeprecated: true) { name }
          possibleTypes { name }
          interfaces { name }
        }
      }
    }
    fragment TypeRef on __Type {
      kind name
      ofType { kind name ofType { kind name ofType { kind name
        ofType { kind name ofType { kind name ofType { kind name } } } } } }
    }
    GQL;

    /**
     * Render a nested type reference into a GraphQL signature. Public so the
     * schema differ describes types exactly as the schema panel does.
     */
    public function signature(array $ref): string
    {
        return $this->renderType($ref);
    }

    /**
     * @param  array  $schema  the decoded `data.__schema` object
     * @return array{query_type: ?string, mutation_type: ?string, type_count: int, operations: array<int,array<string,mixed>>}
     */
    public function summarise(array $schema): array
    {
        $queryType = $schema['queryType']['name'] ?? null;
        $mutationType = $schema['mutationType']['name'] ?? null;

        $byName = [];
        $userTypes = 0;
        foreach ($schema['types'] ?? [] as $type) {
            $name = $type['name'] ?? null;
            if ($name === null) {
                continue;
            }
            $byName[$name] = $type;
            if (! str_starts_with($name, '__')) {
                $userTypes++;
            }
        }

        $operations = [];
        foreach ([[$queryType, 'query'], [$mutationType, 'mutation']] as [$rootName, $kind]) {
            if ($rootName === null || ! isset($byName[$rootName])) {
                continue;
            }
            foreach ($byName[$rootName]['fields'] ?? [] as $field) {
                $operations[] = [
                    'name' => $field['name'],
                    'kind' => $kind,
                    'description' => $field['description'] ?? null,
                    'returns' => $this->renderType($field['type'] ?? []),
                    'args' => array_map(fn ($a) => [
                        'name' => $a['name'],
                        'type' => $this->renderType($a['type'] ?? []),
                    ], $field['args'] ?? []),
                ];
            }
        }

        return [
            'query_type' => $queryType,
            'mutation_type' => $mutationType,
            'type_count' => $userTypes,
            'operations' => $operations,
        ];
    }

    /** Render a nested type reference into a GraphQL signature (e.g. `[User!]!`). */
    private function renderType(array $ref): string
    {
        $kind = $ref['kind'] ?? null;

        if ($kind === 'NON_NULL') {
            return $this->renderType($ref['ofType'] ?? []).'!';
        }
        if ($kind === 'LIST') {
            return '['.$this->renderType($ref['ofType'] ?? []).']';
        }

        return (string) ($ref['name'] ?? 'Unknown');
    }
}
