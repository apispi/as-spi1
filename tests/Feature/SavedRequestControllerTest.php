<?php

namespace Tests\Feature;

use App\Models\SavedRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavedRequestControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_saves_a_rest_request_defaulting_protocol(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/saved-requests', [
            'name' => 'Get users',
            'method' => 'GET',
            'url' => 'https://api.example.com/users',
        ]);

        $response->assertStatus(201)->assertJsonPath('protocol', 'rest');

        $this->assertDatabaseHas('saved_requests', [
            'name' => 'Get users',
            'protocol' => 'rest',
            'method' => 'GET',
        ]);
    }

    public function test_it_saves_an_mcp_request_with_params(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/saved-requests', [
            'name' => 'List tools',
            'protocol' => 'mcp',
            'method' => 'tools/list',
            'url' => 'https://mcp.test/mcp',
            'params' => ['cursor' => null],
        ]);

        $response->assertStatus(201)->assertJsonPath('protocol', 'mcp');

        $saved = SavedRequest::first();
        $this->assertSame('mcp', $saved->protocol);
        $this->assertSame('tools/list', $saved->method);
        $this->assertIsArray($saved->params);
    }

    public function test_it_saves_an_a2a_request_with_params(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/saved-requests', [
            'name' => 'Send message',
            'protocol' => 'a2a',
            'method' => 'message/send',
            'url' => 'https://agent.test/a2a',
            'params' => ['message' => ['role' => 'user', 'parts' => [['text' => 'hi']]]],
        ]);

        $response->assertStatus(201)->assertJsonPath('protocol', 'a2a');

        $saved = SavedRequest::first();
        $this->assertSame('a2a', $saved->protocol);
        $this->assertSame(['role' => 'user', 'parts' => [['text' => 'hi']]], $saved->params['message']);
    }

    public function test_it_rejects_an_invalid_protocol(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/saved-requests', [
            'name' => 'Bad protocol',
            'protocol' => 'ftp',
            'method' => 'GET',
            'url' => 'https://api.example.com',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['protocol']);
    }

    public function test_free_plan_users_are_capped_at_ten_saved_requests(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        for ($i = 0; $i < 10; $i++) {
            SavedRequest::create([
                'user_id' => $user->id,
                'name' => "Request {$i}",
                'protocol' => 'rest',
                'method' => 'GET',
                'url' => 'https://api.example.com',
            ]);
        }

        $response = $this->actingAs($user)->postJson('/api/saved-requests', [
            'name' => 'One too many',
            'method' => 'GET',
            'url' => 'https://api.example.com',
        ]);

        $response->assertStatus(422);
        $this->assertSame(10, $user->savedRequests()->count());
    }

    public function test_admins_are_exempt_from_the_saved_request_cap(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        for ($i = 0; $i < 10; $i++) {
            SavedRequest::create([
                'user_id' => $admin->id,
                'name' => "Request {$i}",
                'protocol' => 'rest',
                'method' => 'GET',
                'url' => 'https://api.example.com',
            ]);
        }

        $response = $this->actingAs($admin)->postJson('/api/saved-requests', [
            'name' => 'Eleventh',
            'method' => 'GET',
            'url' => 'https://api.example.com',
        ]);

        $response->assertStatus(201);
    }

    public function test_users_only_see_their_own_saved_requests(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        SavedRequest::create([
            'user_id' => $owner->id,
            'name' => 'Owner request',
            'protocol' => 'rest',
            'method' => 'GET',
            'url' => 'https://api.example.com',
        ]);

        $response = $this->actingAs($other)->getJson('/api/saved-requests');

        $response->assertStatus(200)->assertJsonCount(0);
    }
}
