<?php

namespace Tests\Feature;

use App\Models\InspectionReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DatasetRunTest extends TestCase
{
    use RefreshDatabase;

    private function collection(User $user, string $url)
    {
        $saved = $user->savedRequests()->create([
            'name' => 'Lookup', 'protocol' => 'rest', 'method' => 'GET', 'url' => $url,
        ]);
        $collection = $user->collections()->create(['name' => 'Data suite']);
        $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => 0]);

        return $collection;
    }

    public function test_it_runs_once_per_row_threading_row_fields_as_variables(): void
    {
        $hit = [];
        Http::fake(['api.example.com/*' => function ($request) use (&$hit) {
            $hit[] = $request->url();
            return Http::response(['ok' => true], 200);
        }]);

        $user = User::factory()->create();
        $collection = $this->collection($user, 'https://api.example.com/users/{{id}}');

        $this->actingAs($user)->postJson("/api/collections/{$collection->id}/run-dataset", [
            'dataset' => [['id' => 1], ['id' => 2], ['id' => 3]],
        ])->assertOk()
            ->assertJsonPath('passed', true)
            ->assertJsonPath('rows', 3)
            ->assertJsonPath('passed_rows', 3)
            ->assertJsonPath('iterations.0.variables', ['id']);

        // Each row produced its own request.
        $this->assertContains('https://api.example.com/users/1', $hit);
        $this->assertContains('https://api.example.com/users/3', $hit);
    }

    public function test_a_row_that_fails_its_assertions_is_reported(): void
    {
        // id 2 returns 500; its row fails, others pass.
        Http::fake(['api.example.com/*' => function ($request) {
            return Http::response([], str_contains($request->url(), '/2') ? 500 : 200);
        }]);

        $user = User::factory()->create();
        $saved = $user->savedRequests()->create([
            'name' => 'Lookup', 'protocol' => 'rest', 'method' => 'GET',
            'url' => 'https://api.example.com/users/{{id}}',
            'assertions' => [['path' => 'status', 'operator' => 'equals', 'expected' => 200]],
        ]);
        $collection = $user->collections()->create(['name' => 'Data suite']);
        $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => 0]);

        $response = $this->actingAs($user)->postJson("/api/collections/{$collection->id}/run-dataset", [
            'dataset' => [['id' => 1], ['id' => 2], ['id' => 3]],
        ])->assertStatus(422)
            ->assertJsonPath('passed', false)
            ->assertJsonPath('failed_rows', 1);

        $this->assertSame(2, $response->json('iterations.1.row'));
        $this->assertFalse($response->json('iterations.1.passed'));
    }

    public function test_it_accepts_csv_with_a_header_row(): void
    {
        Http::fake(['api.example.com/*' => Http::response([], 200)]);

        $user = User::factory()->create();
        $collection = $this->collection($user, 'https://api.example.com/users/{{id}}?tag={{tag}}');

        $this->actingAs($user)->postJson("/api/collections/{$collection->id}/run-dataset", [
            'dataset_csv' => "id,tag\n1,alpha\n2,beta",
        ])->assertOk()->assertJsonPath('rows', 2);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'users/1?tag=alpha'));
    }

    public function test_row_variables_override_the_environment(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $user = User::factory()->create();
        $user->environments()->create(['name' => 'E', 'variables' => [['key' => 'host', 'value' => 'default.example.com', 'secret' => false]], 'is_default' => true]);
        $collection = $this->collection($user, 'https://{{host}}/ping');

        $this->actingAs($user)->postJson("/api/collections/{$collection->id}/run-dataset", [
            'dataset' => [['host' => 'row.example.com']],
        ])->assertOk();

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://row.example.com/'));
    }

    public function test_it_rejects_an_empty_or_oversized_dataset(): void
    {
        $user = User::factory()->create();
        $collection = $this->collection($user, 'https://api.example.com/x');

        $this->actingAs($user)->postJson("/api/collections/{$collection->id}/run-dataset", ['dataset' => []])
            ->assertStatus(422);

        $big = array_fill(0, 51, ['id' => 1]);
        $this->actingAs($user)->postJson("/api/collections/{$collection->id}/run-dataset", ['dataset' => $big])
            ->assertStatus(422);
    }

    public function test_it_persists_a_dataset_report(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $user = User::factory()->create();
        $collection = $this->collection($user, 'https://api.example.com/x');

        $this->actingAs($user)->postJson("/api/collections/{$collection->id}/run-dataset", [
            'dataset' => [['a' => 1]],
        ])->assertOk();

        $this->assertDatabaseHas('inspection_reports', ['type' => 'dataset_run', 'user_id' => $user->id]);
    }
}
