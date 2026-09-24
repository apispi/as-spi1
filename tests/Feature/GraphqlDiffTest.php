<?php

namespace Tests\Feature;

use App\Models\InspectionReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GraphqlDiffTest extends TestCase
{
    use RefreshDatabase;

    /** An introspection document with one User type carrying the given fields. */
    private function document(array $fieldNames): string
    {
        return json_encode(['data' => ['__schema' => $this->schema($fieldNames)]]);
    }

    private function schema(array $fieldNames): array
    {
        return [
            'queryType' => ['name' => 'Query'],
            'types' => [[
                'kind' => 'OBJECT',
                'name' => 'User',
                'fields' => array_map(fn ($name) => [
                    'name' => $name,
                    'type' => ['kind' => 'SCALAR', 'name' => 'String'],
                    'args' => [],
                ], $fieldNames),
            ]],
        ];
    }

    public function test_it_requires_authentication(): void
    {
        $this->postJson('/api/diff/graphql', ['old' => '{}', 'new' => '{}'])->assertUnauthorized();
    }

    public function test_a_clean_diff_returns_200_and_stores_a_report(): void
    {
        $user = User::factory()->create();
        $document = $this->document(['id', 'email']);

        $this->actingAs($user)->postJson('/api/diff/graphql', ['old' => $document, 'new' => $document])
            ->assertOk()
            ->assertJsonPath('breaking', false)
            ->assertJsonStructure(['report_id', 'summary', 'changes', 'type_count']);

        $report = InspectionReport::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('graphql_diff', $report->type);
        $this->assertStringContainsString('no breaking changes', $report->summary);
        $this->assertSame('pasted document', $report->data['new_source']);
    }

    public function test_a_breaking_diff_returns_422_so_ci_can_gate_on_it(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/diff/graphql', [
            'old' => $this->document(['id', 'email']),
            'new' => $this->document(['id']),
        ])->assertStatus(422)
            ->assertJsonPath('breaking', true)
            ->assertJsonPath('breaking_count', 1);

        $this->assertSame('User.email', $response->json('changes.0.location'));
        $this->assertStringContainsString('1 breaking change', InspectionReport::first()->summary);
    }

    public function test_either_side_can_be_a_live_endpoint(): void
    {
        Http::fake([
            'old.example.com/*' => Http::response(['data' => ['__schema' => $this->schema(['id', 'email'])]], 200),
            'new.example.com/*' => Http::response(['data' => ['__schema' => $this->schema(['id'])]], 200),
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/diff/graphql', [
            'old_url' => 'https://old.example.com/graphql',
            'new_url' => 'https://new.example.com/graphql',
        ])->assertStatus(422)
            ->assertJsonPath('breaking_count', 1);

        // Both endpoints were actually introspected.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'old.example.com')
            && str_contains($r->body(), 'SpiSchemaDiff'));

        $this->assertSame('https://new.example.com/graphql', InspectionReport::first()->data['new_source']);
    }

    public function test_a_pasted_baseline_can_be_compared_against_a_live_endpoint(): void
    {
        // The realistic shape: a schema committed to the repo, checked against
        // what is actually deployed.
        Http::fake(['live.example.com/*' => Http::response(['data' => ['__schema' => $this->schema(['id'])]], 200)]);

        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/diff/graphql', [
            'old' => $this->document(['id', 'email']),
            'new_url' => 'https://live.example.com/graphql',
        ])->assertStatus(422)
            ->assertJsonPath('changes.0.category', 'field_removed');
    }

    public function test_a_missing_side_is_reported_clearly(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/diff/graphql', ['old' => $this->document(['id'])])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Provide the new schema'));
    }

    public function test_an_endpoint_with_introspection_disabled_is_reported(): void
    {
        Http::fake(['locked.example.com/*' => Http::response([
            'errors' => [['message' => 'GraphQL introspection is not allowed']],
        ], 200)]);

        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/diff/graphql', [
            'old' => $this->document(['id']),
            'new_url' => 'https://locked.example.com/graphql',
        ])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'introspection is not allowed'));
    }

    public function test_an_internal_endpoint_is_refused_by_the_ssrf_guard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/diff/graphql', [
            'old' => $this->document(['id']),
            'new_url' => 'http://127.0.0.1/graphql',
        ])->assertStatus(422)->assertJsonValidationErrors('new_url');
    }

    public function test_a_document_that_is_not_a_schema_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/diff/graphql', [
            'old' => '{"hello":"world"}',
            'new' => $this->document(['id']),
        ])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'not a GraphQL introspection result'));
    }
}
