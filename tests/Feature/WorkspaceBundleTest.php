<?php

namespace Tests\Feature;

use App\Http\Controllers\SavedRequestController;
use App\Models\Collection;
use App\Models\Environment;
use App\Models\SavedRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceBundleTest extends TestCase
{
    use RefreshDatabase;

    /** A workspace with one request, one collection using it, one environment. */
    private function populate(User $user): array
    {
        $saved = $user->savedRequests()->create([
            'name' => 'List users', 'protocol' => 'rest', 'method' => 'GET',
            'url' => 'https://api.example.com/users',
            'headers' => ['Accept' => 'application/json'],
            'auth' => ['scheme' => 'bearer', 'token' => 'literal-token'],
            'assertions' => [['path' => 'status', 'operator' => 'equals', 'expected' => '200']],
        ]);

        $collection = $user->collections()->create(['name' => 'Smoke', 'description' => 'The basics']);
        $collection->steps()->create([
            'saved_request_id' => $saved->id, 'position' => 0,
            'extract' => [['name' => 'id', 'path' => '$.id']],
        ]);

        $environment = $user->environments()->create([
            'name' => 'Staging',
            'is_default' => true,
            'variables' => [
                ['key' => 'base_url', 'value' => 'https://staging.example.com', 'secret' => false],
                ['key' => 'token', 'value' => 'super-secret-value', 'secret' => true],
            ],
            'auth' => ['scheme' => 'oauth2_client_credentials', 'token_url' => 'https://auth.example.com/t',
                'client_id' => 'client-1', 'client_secret' => 'very-secret'],
        ]);

        return [$saved, $collection, $environment];
    }

    private function exportFor(User $user): array
    {
        return $this->actingAs($user)->getJson('/api/workspace/export')->assertOk()->json();
    }

    private function importFor(User $user, array $bundle)
    {
        return $this->actingAs($user)->postJson('/api/workspace/import', [
            'document' => json_encode($bundle),
        ]);
    }

    // --------------------------------------------------------------- exporting

    public function test_the_bundle_carries_the_whole_workspace(): void
    {
        $user = User::factory()->create();
        $this->populate($user);

        $bundle = $this->exportFor($user);

        $this->assertSame('1.0', $bundle['spi_workspace']);
        $this->assertCount(1, $bundle['saved_requests']);
        $this->assertCount(1, $bundle['collections']);
        $this->assertCount(1, $bundle['environments']);
        $this->assertSame('List users', $bundle['saved_requests'][0]['name']);
        $this->assertSame('The basics', $bundle['collections'][0]['description']);
    }

    public function test_no_credential_is_ever_in_the_bundle(): void
    {
        // A bundle is a thing people email to each other.
        $user = User::factory()->create();
        $this->populate($user);

        $raw = $this->actingAs($user)->get('/api/workspace/export')->getContent();

        $this->assertStringNotContainsString('super-secret-value', $raw);
        $this->assertStringNotContainsString('very-secret', $raw);
        $this->assertStringNotContainsString('literal-token', $raw);

        $bundle = json_decode($raw, true);
        // The shape survives, so the importer knows what to re-enter.
        $this->assertSame('bearer', $bundle['saved_requests'][0]['auth']['scheme']);
        $this->assertSame('', $bundle['saved_requests'][0]['auth']['token']);
        $this->assertSame('client-1', $bundle['environments'][0]['auth']['client_id']);
        $this->assertSame('', $bundle['environments'][0]['auth']['client_secret']);

        $variables = collect($bundle['environments'][0]['variables']);
        $this->assertSame('', $variables->firstWhere('key', 'token')['value']);
        $this->assertTrue($variables->firstWhere('key', 'token')['secret']);
        // A non-secret value still travels — that is the point of the flag.
        $this->assertSame('https://staging.example.com', $variables->firstWhere('key', 'base_url')['value']);
    }

    public function test_the_export_is_offered_as_a_download_and_is_audited(): void
    {
        $user = User::factory()->create();
        $this->populate($user);

        $response = $this->actingAs($user)->get('/api/workspace/export')->assertOk();

        $this->assertStringContainsString('.spi-workspace.json', $response->headers->get('Content-Disposition'));
        $this->assertDatabaseHas('audit_events', ['user_id' => $user->id, 'action' => 'workspace.exported']);
    }

    // --------------------------------------------------------------- importing

    public function test_a_bundle_round_trips_into_another_account(): void
    {
        $from = User::factory()->create();
        $this->populate($from);
        $bundle = $this->exportFor($from);

        $to = User::factory()->create();
        $this->importFor($to, $bundle)
            ->assertOk()
            ->assertJsonPath('created.saved_requests', 1)
            ->assertJsonPath('created.collections', 1)
            ->assertJsonPath('created.environments', 1);

        $saved = SavedRequest::where('user_id', $to->id)->firstOrFail();
        $this->assertSame('https://api.example.com/users', $saved->url);
        $this->assertSame(['Accept' => 'application/json'], $saved->headers);

        // The step points at the request this import made, not the original.
        $collection = Collection::where('user_id', $to->id)->with('steps')->firstOrFail();
        $this->assertSame($saved->id, $collection->steps->first()->saved_request_id);
        $this->assertSame([['name' => 'id', 'path' => '$.id']], $collection->steps->first()->extract);
    }

    public function test_an_import_never_touches_what_is_already_there(): void
    {
        $user = User::factory()->create();
        [$saved, $collection] = $this->populate($user);
        $bundle = $this->exportFor($user);

        // Importing the workspace back into itself.
        $this->importFor($user, $bundle)->assertOk();

        $this->assertSame('List users', $saved->fresh()->name, 'The original is untouched.');
        $this->assertSame(2, SavedRequest::where('user_id', $user->id)->count());

        // The copy is distinguishable rather than silently overwriting.
        $this->assertTrue(
            SavedRequest::where('user_id', $user->id)->where('name', 'List users (imported)')->exists()
        );
        $this->assertTrue(
            Collection::where('user_id', $user->id)->where('name', 'Smoke (imported)')->exists()
        );
    }

    public function test_importing_twice_numbers_the_copies(): void
    {
        $user = User::factory()->create();
        $this->populate($user);
        $bundle = $this->exportFor($user);

        $this->importFor($user, $bundle)->assertOk();
        $this->importFor($user, $bundle)->assertOk();

        $this->assertTrue(
            SavedRequest::where('user_id', $user->id)->where('name', 'List users (imported 2)')->exists()
        );
    }

    public function test_an_imported_environment_never_becomes_the_default(): void
    {
        // The importing account already has one; moving it would re-point
        // every request that relies on it.
        $from = User::factory()->create();
        $this->populate($from);
        $bundle = $this->exportFor($from);

        $to = User::factory()->create();
        $mine = $to->environments()->create(['name' => 'Mine', 'is_default' => true, 'variables' => []]);

        $this->importFor($to, $bundle)->assertOk();

        $this->assertTrue($mine->fresh()->is_default);
        $this->assertFalse(Environment::where('user_id', $to->id)->where('name', 'Staging')->first()->is_default);
    }

    public function test_a_step_whose_request_is_missing_is_dropped_and_reported(): void
    {
        $user = User::factory()->create();

        $response = $this->importFor($user, [
            'spi_workspace' => '1.0',
            'saved_requests' => [],
            'collections' => [['name' => 'Orphaned', 'steps' => [['ref' => 'r99']]]],
        ])->assertOk();

        $collection = Collection::where('user_id', $user->id)->with('steps')->firstOrFail();
        $this->assertCount(0, $collection->steps, 'A step must not be pointed at something arbitrary.');
        $this->assertStringContainsString('no steps', $response->json('skipped.0'));
    }

    public function test_the_saved_request_limit_is_respected_and_reported(): void
    {
        $user = User::factory()->create();
        $limit = SavedRequestController::FREE_PLAN_LIMIT;

        for ($i = 0; $i < $limit - 1; $i++) {
            $user->savedRequests()->create([
                'name' => 'Existing '.$i, 'protocol' => 'rest', 'method' => 'GET',
                'url' => 'https://api.example.com/'.$i,
            ]);
        }

        $response = $this->importFor($user, [
            'spi_workspace' => '1.0',
            'saved_requests' => [
                ['ref' => 'a', 'name' => 'First', 'method' => 'GET', 'url' => 'https://api.example.com/a'],
                ['ref' => 'b', 'name' => 'Second', 'method' => 'GET', 'url' => 'https://api.example.com/b'],
            ],
        ])->assertOk();

        $this->assertSame(1, $response->json('created.saved_requests'));
        $this->assertSame($limit, SavedRequest::where('user_id', $user->id)->count());
        $this->assertStringContainsString('limit was reached', $response->json('skipped.0'));
    }

    public function test_a_colleagues_work_is_included_since_the_workspace_is_shared(): void
    {
        $owner = User::factory()->create();
        $colleague = User::factory()->create();
        $organisation = \App\Models\Organisation::create([
            'name' => 'Acme', 'slug' => 'acme', 'owner_user_id' => $owner->id,
        ]);
        $owner->update(['organisation_id' => $organisation->id]);
        $colleague->update(['organisation_id' => $organisation->id]);

        $colleague->savedRequests()->create([
            'name' => 'Theirs', 'protocol' => 'rest', 'method' => 'GET', 'url' => 'https://api.example.com/x',
        ]);

        $bundle = $this->exportFor($owner->fresh());

        $this->assertSame('Theirs', $bundle['saved_requests'][0]['name']);
    }

    // ---------------------------------------------------------------- refusals

    public function test_a_document_that_is_not_a_bundle_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/workspace/import', ['document' => '{"hello":"world"}'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'not a Spi workspace bundle'));

        $this->actingAs($user)->postJson('/api/workspace/import', ['document' => 'not json{'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That file is not valid JSON.');
    }

    public function test_an_empty_bundle_imports_nothing_without_erroring(): void
    {
        $user = User::factory()->create();

        $this->importFor($user, ['spi_workspace' => '1.0'])
            ->assertOk()
            ->assertJsonPath('created.saved_requests', 0)
            ->assertJsonPath('created.collections', 0);
    }

    public function test_both_routes_require_authentication(): void
    {
        $this->getJson('/api/workspace/export')->assertUnauthorized();
        $this->postJson('/api/workspace/import', ['document' => '{}'])->assertUnauthorized();
    }
}
