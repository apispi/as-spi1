<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Scx\ScxClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SpiChatTest extends TestCase
{
    use RefreshDatabase;

    protected function fakeScx(string $content = 'Sure — here is how.'): void
    {
        Http::fake([
            ScxClient::ENDPOINT => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
            ], 200),
        ]);
    }

    protected function userWithKey(): User
    {
        return User::factory()->create(['scx_api_key' => 'sk-test', 'scx_model' => 'scx-ai']);
    }

    public function test_it_answers_as_spi_with_product_context(): void
    {
        $this->fakeScx('Open the Tester page and pick MCP.');

        $response = $this->actingAs($this->userWithKey())->postJson('/api/scx/chat', [
            'message' => 'How do I test an MCP server?',
        ]);

        $response->assertOk()->assertJsonPath('response', 'Open the Tester page and pick MCP.');

        Http::assertSent(function ($request) {
            $system = collect($request['messages'])->firstWhere('role', 'system')['content'] ?? '';

            // The assistant is branded Spi and knows what the product actually
            // does, so it can point users at real pages and features.
            return str_contains($system, 'You are Spi')
                && str_contains($system, 'MCP')
                && str_contains($system, 'Environments');
        });
    }

    public function test_it_forwards_conversation_history(): void
    {
        $this->fakeScx();

        $this->actingAs($this->userWithKey())->postJson('/api/scx/chat', [
            'message' => 'And for gRPC?',
            'history' => [
                ['role' => 'user', 'content' => 'How do I test MCP?'],
                ['role' => 'assistant', 'content' => 'Use the Tester page.'],
            ],
        ])->assertOk();

        Http::assertSent(function ($request) {
            $roles = collect($request['messages'])->pluck('role')->all();

            return $roles === ['system', 'user', 'assistant', 'user']
                && last($request['messages'])['content'] === 'And for gRPC?';
        });
    }

    public function test_it_asks_for_a_key_when_the_user_has_none(): void
    {
        $user = User::factory()->create(['scx_api_key' => null]);

        $this->actingAs($user)->postJson('/api/scx/chat', ['message' => 'hi'])
            ->assertStatus(400)
            ->assertJsonStructure(['error']);
    }

    public function test_it_requires_authentication(): void
    {
        $this->postJson('/api/scx/chat', ['message' => 'hi'])->assertStatus(401);
    }
}
