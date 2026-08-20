<?php

namespace Tests\Feature;

use App\Http\Controllers\SavedRequestController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportControllerTest extends TestCase
{
    use RefreshDatabase;

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
            get:
              summary: List pets
              responses:
                '200': {}
            post:
              summary: Create pet
              responses:
                '201': {}
        YAML;
    }

    public function test_it_previews_a_curl_command_without_saving(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/import/curl', [
            'command' => "curl -X POST 'https://api.example.com/x' -d '{\"a\":1}'",
        ])->assertOk()->assertJsonPath('method', 'POST');

        $this->assertSame(0, $user->savedRequests()->count(), 'Preview must not create anything.');
    }

    public function test_a_bad_curl_command_explains_the_problem(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/api/import/curl', [
            'command' => 'wget https://example.com',
        ])->assertStatus(422)->assertJsonPath('message', 'That does not look like a curl command.');
    }

    public function test_it_imports_an_openapi_document_into_saved_requests(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/import/openapi', [
            'document' => $this->spec(),
        ])->assertStatus(201)->assertJsonPath('imported', 2);

        $this->assertSame(2, $user->savedRequests()->count());
        $this->assertSame('{{base_url}}/pets', $user->savedRequests()->first()->url);
    }

    public function test_it_can_build_a_collection_and_environment_in_one_step(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/import/openapi', [
            'document' => $this->spec(),
            'create_collection' => true,
            'create_environment' => true,
        ])->assertStatus(201);

        $collection = $user->collections()->firstOrFail();
        $this->assertSame('Pet Store', $collection->name);
        $this->assertSame(2, $collection->steps()->count());

        // The environment supplies the {{base_url}} the requests reference, so
        // an import is immediately runnable.
        $environment = $user->environments()->firstOrFail();
        $this->assertSame('https://api.petstore.example.com/v1', $environment->map()['base_url']);

        $response->assertJsonPath('collection.name', 'Pet Store');
    }

    public function test_importing_twice_disambiguates_names_instead_of_colliding(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/import/openapi', [
            'document' => $this->spec(), 'create_collection' => true,
        ])->assertStatus(201);

        // Collection names are unique per user, so a second import must not
        // blow up on the constraint.
        $this->actingAs($user)->postJson('/api/import/openapi', [
            'document' => $this->spec(), 'create_collection' => true,
        ])->assertStatus(201);

        $this->assertSame(['Pet Store', 'Pet Store 2'], $user->collections()->orderBy('id')->pluck('name')->all());
        $this->assertSame(4, $user->savedRequests()->count());
    }

    public function test_it_respects_the_saved_request_quota(): void
    {
        $user = User::factory()->create();

        // Fill to one below the cap.
        for ($i = 0; $i < SavedRequestController::FREE_PLAN_LIMIT - 1; $i++) {
            $user->savedRequests()->create([
                'name' => 'Filler '.$i, 'protocol' => 'rest', 'method' => 'GET',
                'url' => 'https://api.example.com/'.$i,
            ]);
        }

        $response = $this->actingAs($user)->postJson('/api/import/openapi', [
            'document' => $this->spec(),
        ])->assertStatus(201);

        $this->assertSame(1, $response->json('imported'));
        $this->assertSame(SavedRequestController::FREE_PLAN_LIMIT, $user->savedRequests()->count());
        $this->assertNotEmpty($response->json('warnings'));
    }

    public function test_it_exports_a_saved_request_in_each_format(): void
    {
        $user = User::factory()->create();
        $saved = $user->savedRequests()->create([
            'name' => 'Create', 'protocol' => 'rest', 'method' => 'POST',
            'url' => 'https://api.example.com/users',
            'headers' => ['Authorization' => 'Bearer {{token}}'],
            'body' => '{"name":"Ada"}',
        ]);

        $curl = $this->actingAs($user)->getJson("/api/saved-requests/{$saved->id}/export?format=curl")
            ->assertOk()->json('snippet');

        $this->assertStringContainsString("curl -X POST 'https://api.example.com/users'", $curl);
        // Variables stay as variables: the snippet is for a human to paste,
        // and substituting a secret into copyable text defeats the purpose.
        $this->assertStringContainsString('Bearer {{token}}', $curl);

        foreach (['fetch', 'python', 'http'] as $format) {
            $snippet = $this->actingAs($user)->getJson("/api/saved-requests/{$saved->id}/export?format={$format}")
                ->assertOk()->json('snippet');
            $this->assertStringContainsString('api.example.com', $snippet, "Format {$format} lost the URL.");
        }
    }

    public function test_curl_export_quotes_safely(): void
    {
        $user = User::factory()->create();
        $saved = $user->savedRequests()->create([
            'name' => 'Tricky', 'protocol' => 'rest', 'method' => 'POST',
            'url' => 'https://api.example.com/x',
            'body' => "{\"quote\":\"it's here\"}",
        ]);

        $snippet = $this->actingAs($user)->getJson("/api/saved-requests/{$saved->id}/export")
            ->assertOk()->json('snippet');

        // A single quote inside a single-quoted shell string must be escaped
        // the only way a POSIX shell allows, or the command breaks.
        $this->assertStringContainsString("'\\''", $snippet);
    }

    public function test_it_exports_an_unsaved_draft(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/api/export', [
            'method' => 'GET',
            'url' => 'https://api.example.com/ping',
            'format' => 'http',
        ])->assertOk()
            ->assertJsonPath('snippet', "GET /ping HTTP/1.1\nHost: api.example.com");
    }

    public function test_it_rejects_an_unknown_export_format(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/api/export', [
            'url' => 'https://api.example.com/x', 'format' => 'cobol',
        ])->assertStatus(422)->assertJsonValidationErrors(['format']);
    }

    public function test_a_user_cannot_export_another_users_request(): void
    {
        $owner = User::factory()->create();
        $saved = $owner->savedRequests()->create([
            'name' => 'Theirs', 'protocol' => 'rest', 'method' => 'GET',
            'url' => 'https://api.example.com/x',
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson("/api/saved-requests/{$saved->id}/export")
            ->assertStatus(404);
    }

    public function test_import_and_export_require_authentication(): void
    {
        $this->postJson('/api/import/curl', ['command' => 'curl https://x.com'])->assertStatus(401);
        $this->postJson('/api/import/openapi', ['document' => 'x'])->assertStatus(401);
    }
}
