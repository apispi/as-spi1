<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\SavedRequest;
use App\Models\User;
use App\Services\Import\ImportException;
use App\Services\Import\PostmanImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostmanImportTest extends TestCase
{
    use RefreshDatabase;

    private function collectionJson(): string
    {
        return json_encode([
            'info' => ['name' => 'Widget API', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
            'item' => [
                [
                    'name' => 'List widgets',
                    'request' => [
                        'method' => 'GET',
                        'header' => [
                            ['key' => 'Accept', 'value' => 'application/json'],
                            ['key' => 'X-Debug', 'value' => '1', 'disabled' => true],
                        ],
                        'url' => ['raw' => 'https://{{base_url}}/widgets?limit=25', 'host' => ['{{base_url}}'], 'path' => ['widgets']],
                    ],
                ],
                [
                    'name' => 'Folder',
                    'item' => [
                        [
                            'name' => 'Create widget',
                            'request' => [
                                'method' => 'POST',
                                'header' => [['key' => 'Content-Type', 'value' => 'application/json']],
                                'url' => 'https://{{base_url}}/widgets',
                                'body' => ['mode' => 'raw', 'raw' => '{"name":"Ada"}'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_the_importer_parses_requests_and_flattens_folders(): void
    {
        $parsed = (new PostmanImporter)->parse($this->collectionJson());

        $this->assertSame('Widget API', $parsed['title']);
        $this->assertCount(2, $parsed['operations']);

        $list = $parsed['operations'][0];
        $this->assertSame('GET', $list['method']);
        $this->assertSame('https://{{base_url}}/widgets?limit=25', $list['url']);
        $this->assertSame(['Accept' => 'application/json'], $list['headers']); // disabled header dropped

        $create = $parsed['operations'][1];
        $this->assertSame('POST', $create['method']);
        $this->assertSame('{"name":"Ada"}', $create['body']);
    }

    public function test_invalid_json_is_rejected(): void
    {
        $this->expectException(ImportException::class);
        (new PostmanImporter)->parse('not json');
    }

    public function test_a_non_collection_is_rejected(): void
    {
        $this->expectException(ImportException::class);
        (new PostmanImporter)->parse('{"openapi":"3.0.0"}');
    }

    public function test_the_endpoint_creates_saved_requests_and_a_collection(): void
    {
        $user = User::factory()->create();

        $res = $this->actingAs($user)->postJson('/api/import/postman', [
            'document' => $this->collectionJson(),
            'create_collection' => true,
        ])->assertStatus(201)->assertJsonPath('imported', 2);

        $this->assertSame(2, SavedRequest::where('user_id', $user->id)->count());
        $collection = Collection::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(2, $collection->steps()->count());
        $this->assertStringContainsString('Imported from Postman', $collection->description);
    }

    public function test_the_endpoint_rejects_a_bad_document(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/import/postman', ['document' => '{"nope":true}'])
            ->assertStatus(422);
    }
}
