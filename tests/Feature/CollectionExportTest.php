<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectionExportTest extends TestCase
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

    public function test_it_exports_a_postman_v2_collection_preserving_variables(): void
    {
        $user = User::factory()->create();
        $collection = $this->collectionWithStep($user);

        $response = $this->actingAs($user)->getJson("/api/collections/{$collection->id}/export")
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="widget-suite.postman_collection.json"');

        $doc = $response->json();
        $this->assertStringContainsString('v2.1.0', $doc['info']['schema']);
        $this->assertSame('Widget suite', $doc['info']['name']);

        $item = $doc['item'][0];
        $this->assertSame('Get widget', $item['name']);
        $this->assertSame('GET', $item['request']['method']);
        // Postman uses the same {{var}} syntax, so variables carry across.
        $this->assertSame('https://{{base_url}}/widgets/{{id}}', $item['request']['url']['raw']);
        $this->assertSame('Authorization', $item['request']['header'][0]['key']);
        $this->assertSame('Bearer {{token}}', $item['request']['header'][0]['value']);
    }

    public function test_assertions_become_postman_test_scripts(): void
    {
        $user = User::factory()->create();
        $collection = $this->collectionWithStep($user, [
            'assertions' => [
                ['path' => 'status', 'operator' => 'equals', 'expected' => '200', 'description' => 'ok'],
                ['path' => 'data.id', 'operator' => 'is_type', 'expected' => 'number'],
                ['path' => 'data.items', 'operator' => 'has_length', 'expected' => 3],
            ],
        ]);

        $doc = $this->actingAs($user)->getJson("/api/collections/{$collection->id}/export")->json();
        $script = implode("\n", $doc['item'][0]['event'][0]['script']['exec']);

        $this->assertStringContainsString('pm.test("ok"', $script);
        $this->assertStringContainsString('pm.response.code).to.eql(200)', $script);
        $this->assertStringContainsString("to.be.a('number')", $script);
        $this->assertStringContainsString('to.have.lengthOf(3)', $script);
        $this->assertStringContainsString('pm.response.json()', $script);
    }

    public function test_a_step_without_assertions_has_no_test_event(): void
    {
        $user = User::factory()->create();
        $collection = $this->collectionWithStep($user);

        $doc = $this->actingAs($user)->getJson("/api/collections/{$collection->id}/export")->json();
        $this->assertArrayNotHasKey('event', $doc['item'][0]);
    }

    public function test_a_body_becomes_a_raw_json_body(): void
    {
        $user = User::factory()->create();
        $collection = $this->collectionWithStep($user, ['method' => 'POST', 'body' => '{"name":"Ada"}']);

        $doc = $this->actingAs($user)->getJson("/api/collections/{$collection->id}/export")->json();
        $this->assertSame('{"name":"Ada"}', $doc['item'][0]['request']['body']['raw']);
        $this->assertSame('json', $doc['item'][0]['request']['body']['options']['raw']['language']);
    }

    public function test_export_is_workspace_scoped(): void
    {
        $owner = User::factory()->create();
        $collection = $this->collectionWithStep($owner);

        $this->actingAs(User::factory()->create())
            ->getJson("/api/collections/{$collection->id}/export")->assertStatus(404);
    }
}
