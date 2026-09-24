<?php

namespace Tests\Unit\Services;

use App\Services\Diff\GraphqlSchemaDiffer;
use App\Services\Graphql\GraphqlIntrospector;
use App\Services\Import\ImportException;
use PHPUnit\Framework\TestCase;

class GraphqlSchemaDifferTest extends TestCase
{
    private GraphqlSchemaDiffer $differ;

    protected function setUp(): void
    {
        parent::setUp();
        $this->differ = new GraphqlSchemaDiffer(new GraphqlIntrospector);
    }

    /** A named type reference, optionally wrapped in NON_NULL / LIST. */
    private function ref(string $name, bool $nonNull = false, bool $list = false, bool $listNonNull = false): array
    {
        $inner = ['kind' => 'SCALAR', 'name' => $name];

        if ($nonNull) {
            $inner = ['kind' => 'NON_NULL', 'name' => null, 'ofType' => $inner];
        }
        if ($list) {
            $inner = ['kind' => 'LIST', 'name' => null, 'ofType' => $inner];
            if ($listNonNull) {
                $inner = ['kind' => 'NON_NULL', 'name' => null, 'ofType' => $inner];
            }
        }

        return $inner;
    }

    private function field(string $name, array $type, array $args = []): array
    {
        return ['name' => $name, 'type' => $type, 'args' => $args];
    }

    private function arg(string $name, array $type, $default = null): array
    {
        return ['name' => $name, 'type' => $type, 'defaultValue' => $default];
    }

    /** @param array<int, array> $types */
    private function schema(array $types): array
    {
        return ['queryType' => ['name' => 'Query'], 'types' => $types];
    }

    private function objectType(string $name, array $fields): array
    {
        return ['kind' => 'OBJECT', 'name' => $name, 'fields' => $fields];
    }

    private function categories(array $result): array
    {
        return array_column($result['changes'], 'category');
    }

    // ----------------------------------------------------------------- types

    public function test_identical_schemas_have_no_changes(): void
    {
        $schema = $this->schema([$this->objectType('User', [$this->field('id', $this->ref('ID', true))])]);

        $result = $this->differ->diff($schema, $schema);

        $this->assertFalse($result['breaking']);
        $this->assertSame([], $result['changes']);
        $this->assertSame(1, $result['type_count']);
    }

    public function test_a_removed_type_is_breaking_and_a_new_one_is_informational(): void
    {
        $old = $this->schema([
            $this->objectType('User', [$this->field('id', $this->ref('ID'))]),
            $this->objectType('Order', [$this->field('id', $this->ref('ID'))]),
        ]);
        $new = $this->schema([
            $this->objectType('User', [$this->field('id', $this->ref('ID'))]),
            $this->objectType('Invoice', [$this->field('id', $this->ref('ID'))]),
        ]);

        $result = $this->differ->diff($old, $new);

        $this->assertTrue($result['breaking']);
        $removed = collect($result['changes'])->firstWhere('category', 'type_removed');
        $this->assertSame('Order', $removed['location']);
        $added = collect($result['changes'])->firstWhere('category', 'type_added');
        $this->assertSame(GraphqlSchemaDiffer::SEVERITY_INFO, $added['severity']);
    }

    public function test_a_changed_type_kind_is_breaking_and_suppresses_the_detail(): void
    {
        $old = $this->schema([$this->objectType('Thing', [$this->field('id', $this->ref('ID'))])]);
        $new = $this->schema([['kind' => 'INTERFACE', 'name' => 'Thing', 'fields' => []]]);

        $result = $this->differ->diff($old, $new);

        $this->assertSame(['type_kind_changed'], $this->categories($result));
    }

    public function test_built_in_scalars_and_introspection_types_are_ignored(): void
    {
        $old = $this->schema([
            $this->objectType('User', [$this->field('id', $this->ref('ID'))]),
            ['kind' => 'SCALAR', 'name' => 'String'],
            ['kind' => 'OBJECT', 'name' => '__Type', 'fields' => []],
        ]);
        $new = $this->schema([$this->objectType('User', [$this->field('id', $this->ref('ID'))])]);

        $this->assertSame([], $this->differ->diff($old, $new)['changes']);
    }

    // ---------------------------------------------------------------- fields

    public function test_a_removed_field_is_breaking(): void
    {
        $old = $this->schema([$this->objectType('User', [
            $this->field('id', $this->ref('ID')), $this->field('email', $this->ref('String')),
        ])]);
        $new = $this->schema([$this->objectType('User', [$this->field('id', $this->ref('ID'))])]);

        $result = $this->differ->diff($old, $new);

        $removed = collect($result['changes'])->firstWhere('category', 'field_removed');
        $this->assertSame('User.email', $removed['location']);
        $this->assertSame(GraphqlSchemaDiffer::SEVERITY_BREAKING, $removed['severity']);
    }

    public function test_an_added_field_is_informational(): void
    {
        $old = $this->schema([$this->objectType('User', [$this->field('id', $this->ref('ID'))])]);
        $new = $this->schema([$this->objectType('User', [
            $this->field('id', $this->ref('ID')), $this->field('email', $this->ref('String')),
        ])]);

        $result = $this->differ->diff($old, $new);

        $this->assertFalse($result['breaking']);
        $this->assertContains('field_added', $this->categories($result));
    }

    public function test_a_changed_field_type_is_breaking(): void
    {
        $old = $this->schema([$this->objectType('User', [$this->field('age', $this->ref('Int'))])]);
        $new = $this->schema([$this->objectType('User', [$this->field('age', $this->ref('String'))])]);

        $result = $this->differ->diff($old, $new);

        $changed = collect($result['changes'])->firstWhere('category', 'field_type_changed');
        $this->assertStringContainsString('Int to String', $changed['detail']);
    }

    public function test_an_output_field_losing_non_null_is_breaking_but_gaining_it_is_not(): void
    {
        // Output nullability: looser can hand a client a null it never handled;
        // stricter only promises more.
        $nullable = $this->schema([$this->objectType('User', [$this->field('email', $this->ref('String'))])]);
        $required = $this->schema([$this->objectType('User', [$this->field('email', $this->ref('String', true))])]);

        $loosened = $this->differ->diff($required, $nullable);
        $this->assertTrue($loosened['breaking']);
        $this->assertContains('field_type_loosened', $this->categories($loosened));

        $tightened = $this->differ->diff($nullable, $required);
        $this->assertFalse($tightened['breaking']);
        $this->assertContains('field_type_tightened', $this->categories($tightened));
    }

    // ------------------------------------------------------------- arguments

    public function test_a_removed_argument_is_breaking(): void
    {
        $old = $this->schema([$this->objectType('Query', [
            $this->field('users', $this->ref('String'), [$this->arg('first', $this->ref('Int'))]),
        ])]);
        $new = $this->schema([$this->objectType('Query', [$this->field('users', $this->ref('String'))])]);

        $result = $this->differ->diff($old, $new);

        $removed = collect($result['changes'])->firstWhere('category', 'argument_removed');
        $this->assertSame('Query.users(first:)', $removed['location']);
    }

    public function test_a_new_required_argument_is_breaking_but_an_optional_one_is_not(): void
    {
        $old = $this->schema([$this->objectType('Query', [$this->field('users', $this->ref('String'))])]);

        $withRequired = $this->schema([$this->objectType('Query', [
            $this->field('users', $this->ref('String'), [$this->arg('tenant', $this->ref('ID', true))]),
        ])]);
        $withOptional = $this->schema([$this->objectType('Query', [
            $this->field('users', $this->ref('String'), [$this->arg('tenant', $this->ref('ID'))]),
        ])]);

        $this->assertTrue($this->differ->diff($old, $withRequired)['breaking']);
        $this->assertContains('required_argument_added', $this->categories($this->differ->diff($old, $withRequired)));

        $optional = $this->differ->diff($old, $withOptional);
        $this->assertFalse($optional['breaking']);
        $this->assertContains('optional_argument_added', $this->categories($optional));
    }

    public function test_a_new_non_null_argument_with_a_default_is_not_breaking(): void
    {
        // A default means existing queries that omit it still work.
        $old = $this->schema([$this->objectType('Query', [$this->field('users', $this->ref('String'))])]);
        $new = $this->schema([$this->objectType('Query', [
            $this->field('users', $this->ref('String'), [$this->arg('first', $this->ref('Int', true), '10')]),
        ])]);

        $result = $this->differ->diff($old, $new);

        $this->assertFalse($result['breaking']);
        $this->assertContains('optional_argument_added', $this->categories($result));
    }

    public function test_an_argument_becoming_required_is_breaking_and_the_reverse_is_not(): void
    {
        $optional = $this->schema([$this->objectType('Query', [
            $this->field('users', $this->ref('String'), [$this->arg('tenant', $this->ref('ID'))]),
        ])]);
        $required = $this->schema([$this->objectType('Query', [
            $this->field('users', $this->ref('String'), [$this->arg('tenant', $this->ref('ID', true))]),
        ])]);

        $tightened = $this->differ->diff($optional, $required);
        $this->assertTrue($tightened['breaking']);
        $this->assertContains('input_now_required', $this->categories($tightened));

        $relaxed = $this->differ->diff($required, $optional);
        $this->assertFalse($relaxed['breaking']);
        $this->assertContains('input_now_optional', $this->categories($relaxed));
    }

    // ----------------------------------------------------------- input types

    public function test_input_object_fields_follow_the_same_rules_as_arguments(): void
    {
        $old = ['queryType' => ['name' => 'Query'], 'types' => [[
            'kind' => 'INPUT_OBJECT', 'name' => 'UserFilter',
            'inputFields' => [$this->arg('name', $this->ref('String'))],
        ]]];
        $new = ['queryType' => ['name' => 'Query'], 'types' => [[
            'kind' => 'INPUT_OBJECT', 'name' => 'UserFilter',
            'inputFields' => [$this->arg('tenant', $this->ref('ID', true))],
        ]]];

        $result = $this->differ->diff($old, $new);

        $this->assertTrue($result['breaking']);
        $this->assertContains('input_field_removed', $this->categories($result));
        $this->assertContains('required_input_field_added', $this->categories($result));
    }

    // ------------------------------------------------------- enums and unions

    public function test_a_removed_enum_value_is_breaking_and_an_added_one_is_not(): void
    {
        $old = ['types' => [[
            'kind' => 'ENUM', 'name' => 'Status',
            'enumValues' => [['name' => 'ACTIVE'], ['name' => 'ARCHIVED']],
        ]]];
        $new = ['types' => [[
            'kind' => 'ENUM', 'name' => 'Status',
            'enumValues' => [['name' => 'ACTIVE'], ['name' => 'DELETED']],
        ]]];

        $result = $this->differ->diff($old, $new);

        $this->assertSame(1, $result['breaking_count']);
        $removed = collect($result['changes'])->firstWhere('category', 'enum_value_removed');
        $this->assertStringContainsString('ARCHIVED', $removed['detail']);
        $added = collect($result['changes'])->firstWhere('category', 'enum_value_added');
        $this->assertSame(GraphqlSchemaDiffer::SEVERITY_NON_BREAKING, $added['severity']);
    }

    public function test_a_removed_union_member_is_breaking(): void
    {
        $old = ['types' => [[
            'kind' => 'UNION', 'name' => 'SearchResult',
            'possibleTypes' => [['name' => 'User'], ['name' => 'Order']],
        ]]];
        $new = ['types' => [[
            'kind' => 'UNION', 'name' => 'SearchResult',
            'possibleTypes' => [['name' => 'User']],
        ]]];

        $result = $this->differ->diff($old, $new);

        $this->assertContains('union_member_removed', $this->categories($result));
        $this->assertTrue($result['breaking']);
    }

    // -------------------------------------------------------------- loading

    public function test_it_accepts_a_full_introspection_response_as_json(): void
    {
        $document = json_encode(['data' => ['__schema' => $this->schema([
            $this->objectType('User', [$this->field('id', $this->ref('ID'))]),
        ])]]);

        $result = $this->differ->diff($document, $document);

        $this->assertSame([], $result['changes']);
        $this->assertSame(1, $result['type_count']);
    }

    public function test_it_rejects_a_document_that_is_not_an_introspection_result(): void
    {
        $this->expectException(ImportException::class);
        $this->differ->diff('{"hello":"world"}', '{"hello":"world"}');
    }

    public function test_it_rejects_invalid_json(): void
    {
        $this->expectException(ImportException::class);
        $this->differ->diff('not json{', '{"types":[]}');
    }

    public function test_breaking_changes_are_listed_before_the_rest(): void
    {
        $old = $this->schema([$this->objectType('User', [
            $this->field('id', $this->ref('ID')), $this->field('email', $this->ref('String')),
        ])]);
        $new = $this->schema([$this->objectType('User', [
            $this->field('id', $this->ref('ID')), $this->field('name', $this->ref('String')),
        ])]);

        $result = $this->differ->diff($old, $new);

        $this->assertSame(GraphqlSchemaDiffer::SEVERITY_BREAKING, $result['changes'][0]['severity']);
        $this->assertStringContainsString('1 breaking', $result['summary']);
    }
}
