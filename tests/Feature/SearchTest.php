<?php

namespace Tests\Feature;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_searches_across_workspace_entity_types(): void
    {
        $user = User::factory()->create();
        $user->savedRequests()->create(['name' => 'Widget lookup', 'protocol' => 'rest', 'method' => 'GET', 'url' => 'https://api.example.com/widgets']);
        $user->collections()->create(['name' => 'Widget suite']);
        $user->monitors()->create(['collection_id' => $user->collections()->first()->id, 'name' => 'Widget monitor', 'interval_minutes' => 60]);

        $results = $this->actingAs($user)->getJson('/api/search?q=widget')->assertOk()->json('results');

        $types = array_column($results, 'type');
        $this->assertContains('saved_request', $types);
        $this->assertContains('collection', $types);
        $this->assertContains('monitor', $types);
    }

    public function test_it_matches_a_saved_request_by_url(): void
    {
        $user = User::factory()->create();
        $user->savedRequests()->create(['name' => 'Anything', 'protocol' => 'rest', 'method' => 'GET', 'url' => 'https://payments.acme.io/charge']);

        $results = $this->actingAs($user)->getJson('/api/search?q=payments.acme')->assertOk()->json('results');
        $this->assertSame('Anything', $results[0]['label']);
    }

    public function test_results_are_workspace_scoped(): void
    {
        $org = Organisation::create(['name' => 'Acme', 'slug' => 'acme']);
        $alice = User::factory()->create(['organisation_id' => $org->id]);
        $bob = User::factory()->create(['organisation_id' => $org->id]);
        $outsider = User::factory()->create();

        $alice->savedRequests()->create(['name' => 'Shared thing', 'protocol' => 'rest', 'method' => 'GET', 'url' => 'https://x/']);

        // A colleague sees it; an outsider does not.
        $this->assertNotEmpty($this->actingAs($bob)->getJson('/api/search?q=shared')->json('results'));
        $this->assertEmpty($this->actingAs($outsider)->getJson('/api/search?q=shared')->json('results'));
    }

    public function test_it_requires_authentication_and_a_query(): void
    {
        $this->getJson('/api/search?q=x')->assertStatus(401);
        $this->actingAs(User::factory()->create())->getJson('/api/search')->assertStatus(422);
    }
}
