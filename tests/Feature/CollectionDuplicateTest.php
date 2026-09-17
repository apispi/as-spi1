<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectionDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private function collectionWithSteps(User $user, string $name = 'Smoke'): Collection
    {
        $a = $user->savedRequests()->create(['name' => 'A', 'protocol' => 'rest', 'method' => 'GET', 'url' => 'https://x/a']);
        $b = $user->savedRequests()->create(['name' => 'B', 'protocol' => 'rest', 'method' => 'GET', 'url' => 'https://x/b']);
        $c = $user->collections()->create(['name' => $name, 'description' => 'desc', 'continue_on_failure' => true]);
        $c->steps()->create(['saved_request_id' => $a->id, 'position' => 0, 'extract' => [['name' => 'token', 'path' => 'data.token']]]);
        $c->steps()->create(['saved_request_id' => $b->id, 'position' => 1]);

        return $c;
    }

    public function test_it_clones_the_collection_and_its_steps(): void
    {
        $user = User::factory()->create();
        $source = $this->collectionWithSteps($user);

        $res = $this->actingAs($user)->postJson("/api/collections/{$source->id}/duplicate")
            ->assertStatus(201)
            ->assertJsonPath('name', 'Smoke (copy)');

        $copy = Collection::where('name', 'Smoke (copy)')->firstOrFail();
        $this->assertNotSame($source->id, $copy->id);
        $this->assertSame('desc', $copy->description);
        $this->assertTrue((bool) $copy->continue_on_failure);
        $this->assertSame(2, $copy->steps()->count());
        // Steps point at the same shared saved requests, in the same order.
        $this->assertSame(
            $source->steps()->orderBy('position')->pluck('saved_request_id')->all(),
            $copy->steps()->orderBy('position')->pluck('saved_request_id')->all(),
        );
        $this->assertSame('token', $copy->steps()->orderBy('position')->first()->extract[0]['name']);
    }

    public function test_repeated_duplication_numbers_the_name(): void
    {
        $user = User::factory()->create();
        $source = $this->collectionWithSteps($user);

        $this->actingAs($user)->postJson("/api/collections/{$source->id}/duplicate")->assertStatus(201);
        $this->actingAs($user)->postJson("/api/collections/{$source->id}/duplicate")
            ->assertStatus(201)->assertJsonPath('name', 'Smoke (copy 2)');
    }

    public function test_duplicate_is_workspace_scoped(): void
    {
        $owner = User::factory()->create();
        $source = $this->collectionWithSteps($owner);

        $this->actingAs(User::factory()->create())
            ->postJson("/api/collections/{$source->id}/duplicate")->assertStatus(404);
    }
}
