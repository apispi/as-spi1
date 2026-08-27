<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnvironmentParityTest extends TestCase
{
    use RefreshDatabase;

    private function setup2Envs(User $user): array
    {
        $staging = $user->environments()->create([
            'name' => 'Staging',
            'variables' => [['key' => 'host', 'value' => 'staging.example.com', 'secret' => false]],
        ]);
        $prod = $user->environments()->create([
            'name' => 'Prod',
            'variables' => [['key' => 'host', 'value' => 'prod.example.com', 'secret' => false]],
        ]);
        $saved = $user->savedRequests()->create([
            'name' => 'Widget', 'protocol' => 'rest', 'method' => 'GET',
            'url' => 'https://{{host}}/widget',
        ]);
        $collection = $user->collections()->create(['name' => 'Parity suite']);
        $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => 0]);

        return [$collection, $staging, $prod];
    }

    public function test_matching_shapes_are_in_parity_despite_different_values(): void
    {
        // Same shape, different ids — normal cross-environment value drift.
        Http::fake([
            'staging.example.com/*' => Http::response('{"id":1,"name":"A"}', 200),
            'prod.example.com/*' => Http::response('{"id":999,"name":"B"}', 200),
        ]);

        $user = User::factory()->create();
        [$collection, $staging, $prod] = $this->setup2Envs($user);

        $this->actingAs($user)->postJson("/api/collections/{$collection->id}/parity", [
            'environment_a' => $staging->id, 'environment_b' => $prod->id,
        ])->assertOk()
            ->assertJsonPath('in_parity', true)
            ->assertJsonPath('diverged_count', 0);
    }

    public function test_a_shape_divergence_between_environments_is_flagged(): void
    {
        // Staging gained a field prod does not have, and changed a type.
        Http::fake([
            'staging.example.com/*' => Http::response('{"id":1,"price":"9.99","beta":true}', 200),
            'prod.example.com/*' => Http::response('{"id":1,"price":9.99}', 200),
        ]);

        $user = User::factory()->create();
        [$collection, $staging, $prod] = $this->setup2Envs($user);

        $response = $this->actingAs($user)->postJson("/api/collections/{$collection->id}/parity", [
            'environment_a' => $staging->id, 'environment_b' => $prod->id,
        ])->assertStatus(422)
            ->assertJsonPath('in_parity', false)
            ->assertJsonPath('diverged_count', 1);

        $shape = $response->json('steps.0.shape');
        // "beta" is only in staging (A).
        $this->assertSame('$.beta', $shape['only_in_a'][0]['path']);
        // price is a string in A, number in B.
        $this->assertSame('$.price', $shape['type_differs'][0]['path']);
    }

    public function test_a_status_difference_alone_diverges(): void
    {
        Http::fake([
            'staging.example.com/*' => Http::response('{"id":1}', 200),
            'prod.example.com/*' => Http::response('{"id":1}', 500),
        ]);

        $user = User::factory()->create();
        [$collection, $staging, $prod] = $this->setup2Envs($user);

        $this->actingAs($user)->postJson("/api/collections/{$collection->id}/parity", [
            'environment_a' => $staging->id, 'environment_b' => $prod->id,
        ])->assertStatus(422)
            ->assertJsonPath('steps.0.status_differs', true);
    }

    public function test_it_persists_a_parity_report(): void
    {
        Http::fake(['*' => Http::response('{"id":1}', 200)]);

        $user = User::factory()->create();
        [$collection, $staging, $prod] = $this->setup2Envs($user);

        $this->actingAs($user)->postJson("/api/collections/{$collection->id}/parity", [
            'environment_a' => $staging->id, 'environment_b' => $prod->id,
        ])->assertOk();

        $this->assertDatabaseHas('inspection_reports', ['type' => 'parity', 'user_id' => $user->id]);
    }

    public function test_the_two_environments_must_differ(): void
    {
        $user = User::factory()->create();
        [$collection, $staging] = $this->setup2Envs($user);

        $this->actingAs($user)->postJson("/api/collections/{$collection->id}/parity", [
            'environment_a' => $staging->id, 'environment_b' => $staging->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['environment_b']);
    }

    public function test_secret_values_do_not_leak_into_the_parity_report(): void
    {
        Http::fake(['*' => Http::response('{"token":"super-secret-value"}', 200)]);

        $user = User::factory()->create();
        $a = $user->environments()->create(['name' => 'A', 'variables' => [['key' => 'k', 'value' => 'super-secret-value', 'secret' => true]]]);
        $b = $user->environments()->create(['name' => 'B', 'variables' => [['key' => 'k', 'value' => 'super-secret-value', 'secret' => true]]]);
        $saved = $user->savedRequests()->create([
            'name' => 'Echo', 'protocol' => 'rest', 'method' => 'POST',
            'url' => 'https://api.example.com/echo', 'body' => '{"k":"{{k}}"}',
        ]);
        $collection = $user->collections()->create(['name' => 'S']);
        $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => 0]);

        $response = $this->actingAs($user)->postJson("/api/collections/{$collection->id}/parity", [
            'environment_a' => $a->id, 'environment_b' => $b->id,
        ]);

        $this->assertStringNotContainsString('super-secret-value', $response->getContent());
        $report = \App\Models\InspectionReport::where('type', 'parity')->first();
        $this->assertStringNotContainsString('super-secret-value', json_encode($report->data));
    }
}
