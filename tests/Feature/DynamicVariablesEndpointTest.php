<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DynamicVariablesEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/dynamic-variables')->assertUnauthorized();
    }

    public function test_it_lists_the_dynamic_variable_catalogue(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/dynamic-variables')
            ->assertOk()
            ->assertJsonStructure(['variables' => [['token', 'description', 'example']]])
            ->assertJsonFragment(['token' => '$uuid']);
    }
}
