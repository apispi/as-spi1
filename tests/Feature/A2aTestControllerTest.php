<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class A2aTestControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_reach_the_endpoint(): void
    {
        $response = $this->postJson('/api/a2a/test', [
            'url' => 'https://agent.test/a2a',
            'method' => 'agent-card',
        ]);

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_fetch_an_agent_card(): void
    {
        Http::fake([
            'agent.test/.well-known/agent-card.json' => Http::response(['name' => 'Demo Agent'], 200),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/a2a/test', [
            'url' => 'https://agent.test/a2a',
            'method' => 'agent-card',
        ]);

        $response->assertStatus(200)->assertJsonPath('status', 200);
        $this->assertStringContainsString('Demo Agent', $response->json('body'));
    }

    public function test_authenticated_user_can_send_a_message(): void
    {
        Http::fake([
            'agent.test/a2a' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => ['taskId' => 'task-1'],
            ], 200),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/a2a/test', [
            'url' => 'https://agent.test/a2a',
            'method' => 'message/send',
            'params' => ['message' => ['role' => 'user', 'parts' => [['text' => 'hi']]]],
        ]);

        $response->assertStatus(200);
        $this->assertStringContainsString('task-1', $response->json('body'));
    }

    public function test_returns_500_when_agent_errors(): void
    {
        Http::fake([
            'agent.test/a2a' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => ['code' => -32602, 'message' => 'Invalid params'],
            ], 200),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/a2a/test', [
            'url' => 'https://agent.test/a2a',
            'method' => 'tasks/get',
            'params' => ['id' => 'missing'],
        ]);

        $response->assertStatus(500)->assertJsonPath('status', 500);
    }

    public function test_validates_required_fields(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/a2a/test', []);

        $response->assertStatus(422)->assertJsonValidationErrors(['url', 'method']);
    }
}
