<?php

namespace Tests\Unit\Services;

use App\Services\Diff\OpenApiDiffer;
use App\Services\Import\ImportException;
use PHPUnit\Framework\TestCase;

class OpenApiDifferTest extends TestCase
{
    private OpenApiDiffer $differ;

    protected function setUp(): void
    {
        parent::setUp();
        $this->differ = new OpenApiDiffer;
    }

    /** A small spec builder so each test states only what it changes. */
    private function spec(array $paths, string $version = '1.0.0'): string
    {
        return json_encode([
            'openapi' => '3.0.3',
            'info' => ['title' => 'Widgets API', 'version' => $version],
            'paths' => $paths,
        ]);
    }

    private function categories(array $result): array
    {
        return array_column($result['changes'], 'category');
    }

    public function test_identical_documents_have_no_changes(): void
    {
        $doc = $this->spec(['/widgets' => ['get' => ['responses' => ['200' => ['description' => 'ok']]]]]);

        $result = $this->differ->diff($doc, $doc);

        $this->assertFalse($result['breaking']);
        $this->assertSame(0, $result['breaking_count']);
        $this->assertSame([], $result['changes']);
    }

    public function test_a_removed_endpoint_is_breaking(): void
    {
        $old = $this->spec([
            '/widgets' => ['get' => ['responses' => ['200' => []]]],
            '/gadgets' => ['get' => ['responses' => ['200' => []]]],
        ]);
        $new = $this->spec(['/widgets' => ['get' => ['responses' => ['200' => []]]]]);

        $result = $this->differ->diff($old, $new);

        $this->assertTrue($result['breaking']);
        $this->assertContains('operation_removed', $this->categories($result));
        $removed = collect($result['changes'])->firstWhere('category', 'operation_removed');
        $this->assertSame('GET /gadgets', $removed['operation']);
        $this->assertSame(OpenApiDiffer::SEVERITY_BREAKING, $removed['severity']);
    }

    public function test_an_added_endpoint_is_informational(): void
    {
        $old = $this->spec(['/widgets' => ['get' => ['responses' => ['200' => []]]]]);
        $new = $this->spec([
            '/widgets' => ['get' => ['responses' => ['200' => []]]],
            '/gadgets' => ['get' => ['responses' => ['200' => []]]],
        ]);

        $result = $this->differ->diff($old, $new);

        $this->assertFalse($result['breaking']);
        $added = collect($result['changes'])->firstWhere('category', 'operation_added');
        $this->assertSame('GET /gadgets', $added['operation']);
        $this->assertSame(OpenApiDiffer::SEVERITY_INFO, $added['severity']);
    }

    public function test_a_new_required_parameter_is_breaking_but_an_optional_one_is_not(): void
    {
        $old = $this->spec(['/widgets' => ['get' => ['responses' => ['200' => []]]]]);
        $newRequired = $this->spec(['/widgets' => ['get' => [
            'parameters' => [['name' => 'tenant', 'in' => 'query', 'required' => true]],
            'responses' => ['200' => []],
        ]]]);
        $newOptional = $this->spec(['/widgets' => ['get' => [
            'parameters' => [['name' => 'tenant', 'in' => 'query', 'required' => false]],
            'responses' => ['200' => []],
        ]]]);

        $this->assertTrue($this->differ->diff($old, $newRequired)['breaking']);
        $this->assertContains('required_param_added', $this->categories($this->differ->diff($old, $newRequired)));

        $optional = $this->differ->diff($old, $newOptional);
        $this->assertFalse($optional['breaking']);
        $this->assertContains('optional_param_added', $this->categories($optional));
    }

    public function test_an_optional_parameter_becoming_required_is_breaking(): void
    {
        $param = fn (bool $required) => ['/widgets' => ['get' => [
            'parameters' => [['name' => 'q', 'in' => 'query', 'required' => $required]],
            'responses' => ['200' => []],
        ]]];

        $result = $this->differ->diff($this->spec($param(false)), $this->spec($param(true)));

        $this->assertTrue($result['breaking']);
        $this->assertContains('param_now_required', $this->categories($result));

        // …and the reverse is non-breaking.
        $relaxed = $this->differ->diff($this->spec($param(true)), $this->spec($param(false)));
        $this->assertFalse($relaxed['breaking']);
        $this->assertContains('param_now_optional', $this->categories($relaxed));
    }

    public function test_a_removed_success_response_is_breaking(): void
    {
        $old = $this->spec(['/widgets' => ['post' => ['responses' => ['200' => [], '201' => []]]]]);
        $new = $this->spec(['/widgets' => ['post' => ['responses' => ['200' => []]]]]);

        $result = $this->differ->diff($old, $new);

        $this->assertTrue($result['breaking']);
        $removed = collect($result['changes'])->firstWhere('category', 'success_response_removed');
        $this->assertStringContainsString('201', $removed['detail']);
    }

    public function test_gaining_a_required_request_body_is_breaking(): void
    {
        $old = $this->spec(['/widgets' => ['post' => ['responses' => ['200' => []]]]]);
        $new = $this->spec(['/widgets' => ['post' => [
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object']]]],
            'responses' => ['200' => []],
        ]]]);

        $result = $this->differ->diff($old, $new);

        $this->assertTrue($result['breaking']);
        $this->assertContains('body_now_required', $this->categories($result));
    }

    public function test_path_parameters_are_always_treated_as_required(): void
    {
        // A path parameter with no explicit "required" is still required in
        // OpenAPI, so adding one to an existing operation is breaking.
        $old = $this->spec(['/widgets/{id}' => ['get' => ['responses' => ['200' => []]]]]);
        $new = $this->spec(['/widgets/{id}' => ['get' => [
            'parameters' => [['name' => 'id', 'in' => 'path']],
            'responses' => ['200' => []],
        ]]]);

        $result = $this->differ->diff($old, $new);

        $added = collect($result['changes'])->firstWhere('category', 'required_param_added');
        $this->assertNotNull($added);
        $this->assertSame(OpenApiDiffer::SEVERITY_BREAKING, $added['severity']);
    }

    public function test_it_resolves_component_parameter_refs(): void
    {
        $withRef = json_encode([
            'openapi' => '3.0.3',
            'info' => ['title' => 'API', 'version' => '1.0.0'],
            'components' => ['parameters' => [
                'Tenant' => ['name' => 'tenant', 'in' => 'query', 'required' => true],
            ]],
            'paths' => ['/widgets' => ['get' => [
                'parameters' => [['$ref' => '#/components/parameters/Tenant']],
                'responses' => ['200' => []],
            ]]],
        ]);
        $without = $this->spec(['/widgets' => ['get' => ['responses' => ['200' => []]]]]);

        $result = $this->differ->diff($without, $withRef);

        $added = collect($result['changes'])->firstWhere('category', 'required_param_added');
        $this->assertNotNull($added, 'The $ref parameter should be resolved and compared.');
        $this->assertStringContainsString('tenant', $added['detail']);
    }

    public function test_a_version_change_is_reported_as_informational(): void
    {
        $old = $this->spec(['/widgets' => ['get' => ['responses' => ['200' => []]]]], '1.0.0');
        $new = $this->spec(['/widgets' => ['get' => ['responses' => ['200' => []]]]], '2.0.0');

        $result = $this->differ->diff($old, $new);

        $this->assertSame('1.0.0', $result['old_version']);
        $this->assertSame('2.0.0', $result['new_version']);
        $version = collect($result['changes'])->firstWhere('category', 'version');
        $this->assertSame(OpenApiDiffer::SEVERITY_INFO, $version['severity']);
    }

    public function test_it_parses_yaml_as_well_as_json(): void
    {
        $yaml = <<<'YAML'
        openapi: 3.0.3
        info:
          title: YAML API
          version: 1.0.0
        paths:
          /widgets:
            get:
              responses:
                '200':
                  description: ok
        YAML;

        $result = $this->differ->diff($yaml, $yaml);

        $this->assertSame('YAML API', $result['new_title']);
        $this->assertFalse($result['breaking']);
    }

    public function test_it_rejects_a_non_openapi_document(): void
    {
        $this->expectException(ImportException::class);
        $this->differ->diff('{"paths":{}}', '{"openapi":"3.0.0","paths":{"/x":{"get":{}}}}');
    }

    public function test_it_rejects_swagger_2(): void
    {
        $this->expectException(ImportException::class);
        $this->differ->diff(
            '{"swagger":"2.0","paths":{"/x":{"get":{}}}}',
            '{"openapi":"3.0.0","paths":{"/x":{"get":{}}}}'
        );
    }
}
