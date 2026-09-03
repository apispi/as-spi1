<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenApiExportTest extends TestCase
{
    use RefreshDatabase;

    private function collectionWithStep(User $user, array $savedAttrs = [])
    {
        $saved = $user->savedRequests()->create(array_merge([
            'name' => 'Get widget', 'protocol' => 'rest', 'method' => 'GET',
            'url' => 'https://{{base_url}}/widgets/{{id}}',
            'headers' => ['Authorization' => 'Bearer {{token}}'],
        ], $savedAttrs));
        $collection = $user->collections()->create(['name' => 'Widget suite', 'description' => 'Smoke']);
        $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => 0]);

        return $collection;
    }

    public function test_it_exports_an_openapi_31_document(): void
    {
        $user = User::factory()->create();
        $collection = $this->collectionWithStep($user);

        $response = $this->actingAs($user)->getJson("/api/collections/{$collection->id}/export/openapi")
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="widget-suite.openapi.json"');

        $doc = $response->json();
        $this->assertSame('3.1.0', $doc['openapi']);
        $this->assertSame('Widget suite', $doc['info']['title']);
        $this->assertSame('Smoke', $doc['info']['description']);
    }

    public function test_variables_become_server_variables_and_path_parameters(): void
    {
        $user = User::factory()->create();
        $collection = $this->collectionWithStep($user);

        $doc = $this->actingAs($user)->getJson("/api/collections/{$collection->id}/export/openapi")->json();

        // Origin variable → server variable; single-brace form.
        $this->assertSame('https://{base_url}', $doc['servers'][0]['url']);
        $this->assertArrayHasKey('base_url', $doc['servers'][0]['variables']);

        // Path variable → path parameter.
        $this->assertArrayHasKey('/widgets/{id}', $doc['paths']);
        $op = $doc['paths']['/widgets/{id}']['get'];
        $idParam = collect($op['parameters'])->firstWhere('name', 'id');
        $this->assertSame('path', $idParam['in']);
        $this->assertTrue($idParam['required']);
    }

    public function test_authorization_header_becomes_bearer_security(): void
    {
        $user = User::factory()->create();
        $collection = $this->collectionWithStep($user);

        $doc = $this->actingAs($user)->getJson("/api/collections/{$collection->id}/export/openapi")->json();

        $this->assertSame('http', $doc['components']['securitySchemes']['bearerAuth']['type']);
        $this->assertSame('bearer', $doc['components']['securitySchemes']['bearerAuth']['scheme']);
        $this->assertSame([['bearerAuth' => []]], $doc['security']);

        // The Authorization header is NOT also emitted as a header parameter.
        $op = $doc['paths']['/widgets/{id}']['get'];
        $names = collect($op['parameters'] ?? [])->pluck('name')->all();
        $this->assertNotContains('Authorization', $names);
    }

    public function test_a_contract_becomes_the_response_schema(): void
    {
        $user = User::factory()->create();
        $collection = $this->collectionWithStep($user, [
            'contract' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']],
            'assertions' => [['path' => 'status', 'operator' => 'equals', 'expected' => '200']],
        ]);

        $doc = $this->actingAs($user)->getJson("/api/collections/{$collection->id}/export/openapi")->json();
        $schema = $doc['paths']['/widgets/{id}']['get']['responses']['200']['content']['application/json']['schema'];

        $this->assertSame('object', $schema['type']);
        $this->assertSame('integer', $schema['properties']['id']['type']);
        $this->assertSame(['id'], $schema['required']);
    }

    public function test_a_json_body_becomes_a_request_body_schema(): void
    {
        $user = User::factory()->create();
        $collection = $this->collectionWithStep($user, [
            'method' => 'POST',
            'url' => 'https://{{base_url}}/widgets',
            'body' => '{"name":"Ada","count":3}',
        ]);

        $doc = $this->actingAs($user)->getJson("/api/collections/{$collection->id}/export/openapi")->json();
        $schema = $doc['paths']['/widgets']['post']['requestBody']['content']['application/json']['schema'];

        $this->assertSame('string', $schema['properties']['name']['type']);
        $this->assertSame('integer', $schema['properties']['count']['type']);
    }

    public function test_expected_status_assertion_sets_the_response_code(): void
    {
        $user = User::factory()->create();
        $collection = $this->collectionWithStep($user, [
            'method' => 'POST',
            'url' => 'https://{{base_url}}/widgets',
            'assertions' => [['path' => 'status', 'operator' => 'equals', 'expected' => '201']],
        ]);

        $doc = $this->actingAs($user)->getJson("/api/collections/{$collection->id}/export/openapi")->json();
        $this->assertArrayHasKey('201', $doc['paths']['/widgets']['post']['responses']);
    }

    public function test_non_http_protocols_are_skipped(): void
    {
        $user = User::factory()->create();
        $saved = $user->savedRequests()->create([
            'name' => 'gRPC call', 'protocol' => 'grpc', 'method' => 'POST',
            'url' => 'grpc://{{host}}/Service/Method',
        ]);
        $collection = $user->collections()->create(['name' => 'Mixed', 'description' => null]);
        $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => 0]);

        $doc = $this->actingAs($user)->getJson("/api/collections/{$collection->id}/export/openapi")->json();
        $this->assertSame([], (array) $doc['paths']);
    }

    public function test_export_is_workspace_scoped(): void
    {
        $owner = User::factory()->create();
        $collection = $this->collectionWithStep($owner);

        $this->actingAs(User::factory()->create())
            ->getJson("/api/collections/{$collection->id}/export/openapi")->assertStatus(404);
    }
}
