<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Graphql\GraphqlIntrospector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GraphqlIntrospectionTest extends TestCase
{
    use RefreshDatabase;

    private function schema(): array
    {
        return [
            'queryType' => ['name' => 'Query'],
            'mutationType' => ['name' => 'Mutation'],
            'types' => [
                ['kind' => 'OBJECT', 'name' => 'Query', 'fields' => [
                    ['name' => 'user', 'description' => 'Get a user', 'type' => ['kind' => 'OBJECT', 'name' => 'User'],
                        'args' => [['name' => 'id', 'type' => ['kind' => 'NON_NULL', 'ofType' => ['kind' => 'SCALAR', 'name' => 'ID']]]]],
                    ['name' => 'users', 'description' => null, 'args' => [],
                        'type' => ['kind' => 'LIST', 'ofType' => ['kind' => 'NON_NULL', 'ofType' => ['kind' => 'OBJECT', 'name' => 'User']]]],
                ]],
                ['kind' => 'OBJECT', 'name' => 'Mutation', 'fields' => [
                    ['name' => 'createUser', 'description' => null, 'type' => ['kind' => 'OBJECT', 'name' => 'User'],
                        'args' => [['name' => 'input', 'type' => ['kind' => 'NON_NULL', 'ofType' => ['kind' => 'INPUT_OBJECT', 'name' => 'UserInput']]]]],
                ]],
                ['kind' => 'OBJECT', 'name' => 'User', 'fields' => []],
                ['kind' => 'SCALAR', 'name' => 'ID'],
                ['kind' => 'OBJECT', 'name' => '__Type', 'fields' => []], // introspection type, excluded from count
            ],
        ];
    }

    public function test_summarise_extracts_operations_and_type_signatures(): void
    {
        $summary = (new GraphqlIntrospector)->summarise($this->schema());

        $this->assertSame('Query', $summary['query_type']);
        $this->assertSame('Mutation', $summary['mutation_type']);
        $this->assertSame(4, $summary['type_count']); // __Type excluded

        $ops = collect($summary['operations'])->keyBy('name');
        $this->assertSame('query', $ops['user']['kind']);
        $this->assertSame('User', $ops['user']['returns']);
        $this->assertSame('ID!', $ops['user']['args'][0]['type']);
        $this->assertSame('[User!]', $ops['users']['returns']);
        $this->assertSame('mutation', $ops['createUser']['kind']);
        $this->assertSame('UserInput!', $ops['createUser']['args'][0]['type']);
    }

    public function test_endpoint_introspects_a_graphql_server(): void
    {
        Http::fake(['graph.example.com/*' => Http::response(['data' => ['__schema' => $this->schema()]], 200)]);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/graphql/introspect', ['url' => 'https://graph.example.com/graphql'])
            ->assertOk()
            ->assertJsonPath('query_type', 'Query')
            ->assertJsonCount(3, 'operations');
    }

    public function test_endpoint_rejects_a_non_graphql_response(): void
    {
        Http::fake(['graph.example.com/*' => Http::response(['hello' => 'world'], 200)]);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/graphql/introspect', ['url' => 'https://graph.example.com/graphql'])
            ->assertStatus(422);
    }

    public function test_endpoint_surfaces_a_graphql_error(): void
    {
        Http::fake(['graph.example.com/*' => Http::response(['errors' => [['message' => 'Introspection disabled']]], 200)]);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/graphql/introspect', ['url' => 'https://graph.example.com/graphql'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Introspection failed: Introspection disabled']);
    }

    public function test_endpoint_requires_authentication(): void
    {
        $this->postJson('/api/graphql/introspect', ['url' => 'https://graph.example.com/graphql'])->assertStatus(401);
    }
}
