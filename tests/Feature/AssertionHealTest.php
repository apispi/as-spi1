<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Scx\ScxClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssertionHealTest extends TestCase
{
    use RefreshDatabase;

    private function fakeScx(array $result): void
    {
        Http::fake([
            ScxClient::ENDPOINT => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => json_encode($result)]]],
            ], 200),
        ]);
    }

    private function user(): User
    {
        return User::factory()->create(['scx_api_key' => 'sk-test', 'scx_model' => 'scx-ai']);
    }

    public function test_it_proposes_updated_assertions_with_the_vocabulary_constraint_in_the_prompt(): void
    {
        $this->fakeScx([
            'assertions' => [
                ['path' => 'data.version', 'operator' => 'equals', 'expected' => '2', 'description' => 'API version'],
            ],
            'dropped' => [['path' => 'data.legacy', 'reason' => 'Field removed in the new response.']],
            'summary' => 'Version bumped; legacy field removed.',
        ]);

        $response = $this->actingAs($this->user())->postJson('/api/ai/heal', [
            'assertions' => [
                ['path' => 'data.version', 'operator' => 'equals', 'expected' => '1'],
                ['path' => 'data.legacy', 'operator' => 'exists'],
            ],
            'response' => '{"data":{"version":2}}',
            'status' => 200,
        ]);

        $response->assertOk()
            ->assertJsonPath('assertions.0.expected', '2')
            ->assertJsonPath('dropped.0.path', 'data.legacy');

        // The prompt carries the closed operator list and the repair rules, so
        // every proposal is runnable and intent-preserving by construction.
        Http::assertSent(function ($request) {
            $system = collect($request['messages'])->firstWhere('role', 'system')['content'] ?? '';

            return str_contains($system, 'MUST be one of')
                && str_contains($system, 'has_length')
                && str_contains($system, 'Never invent assertions');
        });
    }

    public function test_input_assertions_are_validated_against_the_vocabulary(): void
    {
        $this->actingAs($this->user())->postJson('/api/ai/heal', [
            'assertions' => [['path' => 'x', 'operator' => 'includes', 'expected' => '1']],
            'response' => '{}',
        ])->assertStatus(422)->assertJsonValidationErrors(['assertions.0.operator']);
    }

    public function test_it_requires_an_scx_key_and_authentication(): void
    {
        $this->postJson('/api/ai/heal', [
            'assertions' => [['path' => 'x', 'operator' => 'exists']],
            'response' => '{}',
        ])->assertStatus(401);

        $keyless = User::factory()->create(['scx_api_key' => null]);
        $this->actingAs($keyless)->postJson('/api/ai/heal', [
            'assertions' => [['path' => 'x', 'operator' => 'exists']],
            'response' => '{}',
        ])->assertStatus(400);
    }
}
