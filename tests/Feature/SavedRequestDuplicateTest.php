<?php

namespace Tests\Feature;

use App\Models\SavedRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavedRequestDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private function saved(User $user, array $overrides = []): SavedRequest
    {
        return $user->savedRequests()->create(array_merge([
            'name' => 'Get widget', 'protocol' => 'rest', 'method' => 'GET',
            'url' => 'https://{{base_url}}/widget',
            'headers' => ['Authorization' => 'Bearer {{token}}'],
            'assertions' => [['path' => 'status', 'operator' => 'equals', 'expected' => '200']],
            'snapshot' => ['status' => 200, 'body' => '{"id":1}'],
            'snapshot_taken_at' => now(),
        ], $overrides));
    }

    public function test_it_clones_the_request_but_not_the_snapshot(): void
    {
        $user = User::factory()->create();
        $source = $this->saved($user);

        $res = $this->actingAs($user)->postJson("/api/saved-requests/{$source->id}/duplicate")
            ->assertStatus(201)
            ->assertJsonPath('name', 'Get widget (copy)');

        $copy = SavedRequest::where('name', 'Get widget (copy)')->firstOrFail();
        $this->assertSame($source->url, $copy->url);
        $this->assertSame($source->headers, $copy->headers);
        $this->assertSame($source->assertions, $copy->assertions);
        // A copy earns its own snapshot baseline.
        $this->assertNull($copy->snapshot);
        $this->assertNull($copy->snapshot_taken_at);
    }

    public function test_repeated_duplication_numbers_the_name(): void
    {
        $user = User::factory()->create();
        $source = $this->saved($user);

        $this->actingAs($user)->postJson("/api/saved-requests/{$source->id}/duplicate")->assertStatus(201);
        $this->actingAs($user)->postJson("/api/saved-requests/{$source->id}/duplicate")
            ->assertStatus(201)->assertJsonPath('name', 'Get widget (copy 2)');
    }

    public function test_duplicate_is_workspace_scoped(): void
    {
        $owner = User::factory()->create();
        $source = $this->saved($owner);

        $this->actingAs(User::factory()->create())
            ->postJson("/api/saved-requests/{$source->id}/duplicate")->assertStatus(404);
    }
}
