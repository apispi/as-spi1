<?php

namespace Tests\Feature;

use App\Models\Environment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnvironmentDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private function env(User $user, string $name = 'Staging'): Environment
    {
        return $user->environments()->create([
            'name' => $name,
            'is_default' => true,
            'variables' => [
                ['key' => 'base_url', 'value' => 'https://api.example.com', 'secret' => false],
                ['key' => 'token', 'value' => 'super-secret', 'secret' => true],
            ],
        ]);
    }

    public function test_it_clones_variables_including_secret_values(): void
    {
        $user = User::factory()->create();
        $source = $this->env($user);

        $res = $this->actingAs($user)->postJson("/api/environments/{$source->id}/duplicate")
            ->assertStatus(201)
            ->assertJsonPath('name', 'Staging (copy)');

        $copy = Environment::where('name', 'Staging (copy)')->firstOrFail();
        // Secret VALUES are kept on an in-workspace clone (unlike export).
        $map = collect($copy->variables)->keyBy('key');
        $this->assertSame('super-secret', $map['token']['value']);
        $this->assertTrue($map['token']['secret']);
        // The copy is never the default.
        $this->assertFalse((bool) $copy->is_default);
        $this->assertTrue((bool) $source->fresh()->is_default);
    }

    public function test_repeated_duplication_numbers_the_name(): void
    {
        $user = User::factory()->create();
        $source = $this->env($user);

        $this->actingAs($user)->postJson("/api/environments/{$source->id}/duplicate")->assertStatus(201);
        $this->actingAs($user)->postJson("/api/environments/{$source->id}/duplicate")
            ->assertStatus(201)->assertJsonPath('name', 'Staging (copy) (2)');
    }

    public function test_duplicate_is_workspace_scoped(): void
    {
        $owner = User::factory()->create();
        $source = $this->env($owner);

        $this->actingAs(User::factory()->create())
            ->postJson("/api/environments/{$source->id}/duplicate")->assertStatus(404);
    }
}
