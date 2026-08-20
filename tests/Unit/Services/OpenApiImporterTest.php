<?php

namespace Tests\Unit\Services;

use App\Services\Import\ImportException;
use App\Services\Import\OpenApiImporter;
use PHPUnit\Framework\TestCase;

class OpenApiImporterTest extends TestCase
{
    private OpenApiImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importer = new OpenApiImporter;
    }

    private function spec(): string
    {
        return <<<'YAML'
        openapi: 3.0.0
        info:
          title: Pet Store
        servers:
          - url: https://api.petstore.example.com/v1
        paths:
          /pets:
            parameters:
              - name: tenant
                in: header
                required: true
            get:
              summary: List pets
              parameters:
                - name: limit
                  in: query
                  required: true
                - name: sort
                  in: query
                  required: false
              responses:
                '200':
                  content:
                    application/json:
                      schema:
                        type: array
            post:
              operationId: createPet
              requestBody:
                content:
                  application/json:
                    schema:
                      type: object
                      properties:
                        name:
                          type: string
                        age:
                          type: integer
              responses:
                '201':
                  content:
                    application/json:
                      schema:
                        type: object
          /pets/{petId}:
            delete:
              summary: Remove a pet
              responses:
                '204': {}
        YAML;
    }

    public function test_it_parses_yaml_and_finds_every_operation(): void
    {
        $parsed = $this->importer->parse($this->spec());

        $this->assertSame('Pet Store', $parsed['title']);
        $this->assertSame('https://api.petstore.example.com/v1', $parsed['base_url']);
        $this->assertCount(3, $parsed['operations']);
    }

    public function test_the_server_url_becomes_a_variable_rather_than_being_baked_in(): void
    {
        // This is the point of importing into Spi: one collection, many
        // environments.
        $get = $this->importer->parse($this->spec())['operations'][0];

        $this->assertStringStartsWith('{{base_url}}/pets', $get['url']);
    }

    public function test_path_parameters_become_variables(): void
    {
        $operations = $this->importer->parse($this->spec())['operations'];
        $delete = collect($operations)->firstWhere('method', 'DELETE');

        $this->assertSame('{{base_url}}/pets/{{petId}}', $delete['url']);
    }

    public function test_required_query_and_header_parameters_are_carried_over(): void
    {
        $get = $this->importer->parse($this->spec())['operations'][0];

        // Required only: an optional "sort" would just add noise.
        $this->assertStringContainsString('limit={{limit}}', $get['url']);
        $this->assertStringNotContainsString('sort=', $get['url']);
        $this->assertSame('{{tenant}}', $get['headers']['tenant'], 'Path-level parameters apply to each method.');
    }

    public function test_a_request_body_is_generated_from_the_schema(): void
    {
        $post = collect($this->importer->parse($this->spec())['operations'])->firstWhere('method', 'POST');

        $this->assertSame('application/json', $post['headers']['Content-Type']);
        $this->assertSame(['name' => '', 'age' => 0], json_decode($post['body'], true));
    }

    public function test_assertions_come_from_the_documented_response(): void
    {
        $operations = $this->importer->parse($this->spec())['operations'];

        $get = $operations[0];
        $this->assertSame('status', $get['assertions'][0]['path']);
        $this->assertSame('200', $get['assertions'][0]['expected']);
        // The documented top-level type, not invented field checks.
        $this->assertSame(['path' => '$', 'operator' => 'is_type', 'expected' => 'array', 'description' => 'Documented response type'], $get['assertions'][1]);

        $post = collect($operations)->firstWhere('method', 'POST');
        $this->assertSame('201', $post['assertions'][0]['expected']);
    }

    public function test_operations_are_named_from_summary_then_operation_id(): void
    {
        $operations = $this->importer->parse($this->spec())['operations'];

        $this->assertSame('List pets', $operations[0]['name']);
        $this->assertSame('createPet', collect($operations)->firstWhere('method', 'POST')['name']);
    }

    public function test_it_parses_json_documents_too(): void
    {
        $json = json_encode([
            'openapi' => '3.0.0',
            'info' => ['title' => 'JSON API'],
            'paths' => ['/things' => ['get' => ['responses' => ['200' => []]]]],
        ]);

        $parsed = $this->importer->parse($json);

        $this->assertSame('JSON API', $parsed['title']);
        $this->assertCount(1, $parsed['operations']);
    }

    public function test_swagger_2_is_rejected_with_an_actionable_message(): void
    {
        $this->expectExceptionMessageMatches('/OpenAPI 3/');
        $this->importer->parse(json_encode(['swagger' => '2.0', 'paths' => []]));
    }

    public function test_it_rejects_documents_that_are_not_openapi(): void
    {
        $this->expectException(ImportException::class);
        $this->importer->parse(json_encode(['hello' => 'world']));
    }

    public function test_it_rejects_invalid_yaml_with_a_reason(): void
    {
        $this->expectExceptionMessageMatches('/Invalid YAML/');
        $this->importer->parse("openapi: 3.0.0\npaths:\n  - : :\n   bad");
    }

    public function test_a_document_with_no_operations_is_rejected(): void
    {
        $this->expectException(ImportException::class);
        $this->importer->parse("openapi: 3.0.0\npaths:\n  /x:\n    description: nothing here");
    }
}
