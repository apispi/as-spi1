<?php

namespace Tests\Feature;

use App\Models\InspectionReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiDiffTest extends TestCase
{
    use RefreshDatabase;

    private function spec(array $paths): string
    {
        return json_encode([
            'openapi' => '3.0.3',
            'info' => ['title' => 'Widgets API', 'version' => '1.0.0'],
            'paths' => $paths,
        ]);
    }

    public function test_it_requires_authentication(): void
    {
        $this->postJson('/api/diff/openapi', ['old' => '{}', 'new' => '{}'])->assertUnauthorized();
    }

    public function test_a_clean_diff_returns_200_and_stores_a_report(): void
    {
        $user = User::factory()->create();
        $doc = $this->spec(['/widgets' => ['get' => ['responses' => ['200' => ['description' => 'ok']]]]]);

        $this->actingAs($user)->postJson('/api/diff/openapi', ['old' => $doc, 'new' => $doc])
            ->assertOk()
            ->assertJsonPath('breaking', false)
            ->assertJsonPath('breaking_count', 0)
            ->assertJsonStructure(['report_id', 'summary', 'changes']);

        $report = InspectionReport::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('api_diff', $report->type);
        $this->assertStringContainsString('no breaking changes', $report->summary);
    }

    public function test_a_breaking_diff_returns_422_so_ci_can_gate_on_it(): void
    {
        $user = User::factory()->create();
        $old = $this->spec([
            '/widgets' => ['get' => ['responses' => ['200' => []]]],
            '/gadgets' => ['get' => ['responses' => ['200' => []]]],
        ]);
        $new = $this->spec(['/widgets' => ['get' => ['responses' => ['200' => []]]]]);

        $response = $this->actingAs($user)->postJson('/api/diff/openapi', ['old' => $old, 'new' => $new])
            ->assertStatus(422)
            ->assertJsonPath('breaking', true)
            ->assertJsonPath('breaking_count', 1);

        $this->assertSame('GET /gadgets', $response->json('changes.0.operation'));

        $report = InspectionReport::where('user_id', $user->id)->firstOrFail();
        $this->assertStringContainsString('1 breaking change', $report->summary);
    }

    public function test_it_rejects_an_invalid_document_with_a_helpful_message(): void
    {
        $user = User::factory()->create();
        $good = $this->spec(['/widgets' => ['get' => ['responses' => ['200' => []]]]]);

        $this->actingAs($user)->postJson('/api/diff/openapi', ['old' => 'not json{', 'new' => $good])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'old document'));
    }

    public function test_both_documents_are_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/diff/openapi', ['old' => '{}'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('new');
    }
}
