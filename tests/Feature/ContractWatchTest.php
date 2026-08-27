<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContractWatchTest extends TestCase
{
    use RefreshDatabase;

    private function saved(User $user, array $attributes = [])
    {
        return $user->savedRequests()->create(array_merge([
            'name' => 'Users', 'protocol' => 'rest', 'method' => 'GET',
            'url' => 'https://api.example.com/users',
        ], $attributes));
    }

    public function test_it_captures_a_contract_from_a_response(): void
    {
        $user = User::factory()->create();
        $saved = $this->saved($user);

        $this->actingAs($user)->putJson("/api/saved-requests/{$saved->id}/contract", [
            'response' => '{"id":1,"email":"a@b.com","active":true}',
        ])->assertOk()
            ->assertJsonPath('contract.type', 'object')
            ->assertJsonPath('contract.properties.email.format', 'email');

        $this->assertNotNull($saved->fresh()->contract);
    }

    public function test_a_non_json_response_cannot_become_a_contract(): void
    {
        $user = User::factory()->create();
        $saved = $this->saved($user);

        $this->actingAs($user)->putJson("/api/saved-requests/{$saved->id}/contract", [
            'response' => '<html>not json</html>',
        ])->assertStatus(422);
    }

    public function test_a_contract_can_be_cleared(): void
    {
        $user = User::factory()->create();
        $saved = $this->saved($user, ['contract' => ['type' => 'object']]);

        $this->actingAs($user)->putJson("/api/saved-requests/{$saved->id}/contract", ['response' => ''])
            ->assertOk()->assertJsonPath('contract', null);

        $this->assertNull($saved->fresh()->contract);
    }

    public function test_live_check_reports_conformance(): void
    {
        $user = User::factory()->create();
        $saved = $this->saved($user, ['contract' => (new \App\Services\Contracts\SchemaInferrer)->infer(['id' => 1, 'name' => 'Ada'])]);

        $this->actingAs($user)->postJson("/api/saved-requests/{$saved->id}/contract/check", [
            'response' => '{"id":2}',
        ])->assertOk()
            ->assertJsonPath('breaking', true)
            ->assertJsonPath('removed.0.path', '$.name');
    }

    public function test_breaking_drift_fails_a_collection_step(): void
    {
        // The API used to return {id, price:number}; now price is a string.
        Http::fake(['api.example.com/*' => Http::response('{"id":1,"price":"9.99"}', 200)]);

        $user = User::factory()->create();
        $saved = $this->saved($user, [
            'url' => 'https://api.example.com/product',
            'contract' => (new \App\Services\Contracts\SchemaInferrer)->infer(['id' => 1, 'price' => 9.99]),
        ]);
        $collection = $user->collections()->create(['name' => 'Contract suite']);
        $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => 0]);

        // No assertions written — the contract alone catches the silent break.
        $this->actingAs($user)->postJson("/api/collections/{$collection->id}/run")
            ->assertStatus(422)
            ->assertJsonPath('passed', false)
            ->assertJsonPath('steps.0.passed', false)
            ->assertJsonPath('steps.0.contract.breaking', true)
            ->assertJsonPath('steps.0.contract.type_changed.0.path', '$.price');
    }

    public function test_additive_drift_does_not_fail_a_step(): void
    {
        Http::fake(['api.example.com/*' => Http::response('{"id":1,"name":"Ada","nickname":"ada"}', 200)]);

        $user = User::factory()->create();
        $saved = $this->saved($user, [
            'contract' => (new \App\Services\Contracts\SchemaInferrer)->infer(['id' => 1, 'name' => 'Ada']),
        ]);
        $collection = $user->collections()->create(['name' => 'Suite']);
        $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => 0]);

        $this->actingAs($user)->postJson("/api/collections/{$collection->id}/run")
            ->assertOk()
            ->assertJsonPath('passed', true)
            ->assertJsonPath('steps.0.contract.breaking', false)
            ->assertJsonPath('steps.0.contract.added.0.path', '$.nickname');
    }

    public function test_a_colleague_can_capture_a_contract_in_the_shared_workspace(): void
    {
        $org = \App\Models\Organisation::create(['name' => 'Acme', 'slug' => 'acme']);
        $alice = User::factory()->create(['organisation_id' => $org->id]);
        $bob = User::factory()->create(['organisation_id' => $org->id]);
        $saved = $this->saved($alice);

        $this->actingAs($bob)->putJson("/api/saved-requests/{$saved->id}/contract", [
            'response' => '{"id":1}',
        ])->assertOk();

        $this->assertNotNull($saved->fresh()->contract);
    }
}
