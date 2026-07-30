<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Scx\ScxClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiAssistControllerTest extends TestCase
{
    use RefreshDatabase;

    /** Fake the SCX completions endpoint to return the given assistant content. */
    protected function fakeScx(string $content): void
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

    public function test_author_returns_a_structured_request(): void
    {
        $this->fakeScx(json_encode([
            'method' => 'GET', 'url' => 'https://api.example.com/users/3',
            'params' => new \stdClass(), 'body' => null, 'headers' => new \stdClass(), 'notes' => 'ok',
        ]));

        $response = $this->actingAs($this->userWithKey())->postJson('/api/ai/author', [
            'instruction' => 'get the third user',
            'protocol' => 'rest',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('method', 'GET')
            ->assertJsonPath('url', 'https://api.example.com/users/3');
    }

    public function test_author_requires_scx_key(): void
    {
        $response = $this->actingAs(User::factory()->create())->postJson('/api/ai/author', [
            'instruction' => 'do a thing',
            'protocol' => 'rest',
        ]);

        $response->assertStatus(400)->assertJsonPath('error', fn ($e) => str_contains($e, 'SCX API key'));
    }

    public function test_explain_summarizes_an_exchange(): void
    {
        $this->fakeScx(json_encode([
            'summary' => 'The server returned 404 because the user does not exist.',
            'likely_cause' => 'Wrong id', 'suggestions' => ['Check the id'],
        ]));

        $response = $this->actingAs($this->userWithKey())->postJson('/api/ai/explain', [
            'response' => '{"error":"not found"}',
            'status' => 404,
        ]);

        $response->assertStatus(200)->assertJsonStructure(['summary', 'likely_cause', 'suggestions']);
    }

    public function test_assert_generates_assertions(): void
    {
        $this->fakeScx(json_encode([
            'assertions' => [
                ['path' => 'status', 'operator' => 'equals', 'expected' => '200', 'description' => 'OK'],
            ],
        ]));

        $response = $this->actingAs($this->userWithKey())->postJson('/api/ai/assert', [
            'response' => '{"id":1}',
            'status' => 200,
        ]);

        $response->assertStatus(200)->assertJsonPath('assertions.0.path', 'status');
    }

    public function test_fix_proposes_a_corrected_request(): void
    {
        $this->fakeScx(json_encode([
            'diagnosis' => 'Missing content-type header.',
            'fixed_request' => ['method' => 'POST', 'url' => 'https://x', 'params' => new \stdClass(), 'body' => '{}', 'headers' => ['Content-Type' => 'application/json']],
            'changes' => ['Added Content-Type'],
        ]));

        $response = $this->actingAs($this->userWithKey())->postJson('/api/ai/fix', [
            'request' => 'POST https://x',
            'error' => '415 Unsupported Media Type',
        ]);

        $response->assertStatus(200)->assertJsonPath('fixed_request.method', 'POST');
    }
}
