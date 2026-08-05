<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\InspectionReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function connector(): CatalogItem
    {
        return CatalogItem::firstOrCreate(
            ['type' => 'connector', 'slug' => 'demo-mcp'],
            ['name' => 'Demo MCP', 'metadata' => ['endpoint' => 'https://mcp.test/mcp', 'protocol' => 'mcp']],
        );
    }

    protected function report(User $user, array $overrides = []): InspectionReport
    {
        return InspectionReport::record(
            $user->id,
            $this->connector(),
            $overrides['type'] ?? 'conformance',
            $overrides['data'] ?? ['grade' => 'A', 'score' => 95, 'checks' => []],
        );
    }

    public function test_index_lists_only_the_owners_reports(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->report($user);
        $this->report($other);

        $response = $this->actingAs($user)->getJson('/api/reports');

        $response->assertStatus(200)->assertJsonCount(1, 'reports');
        $this->assertSame('Grade A (95/100)', $response->json('reports.0.summary'));
    }

    public function test_index_filters_by_type(): void
    {
        $user = User::factory()->create();
        $this->report($user, ['type' => 'conformance']);
        $this->report($user, ['type' => 'security', 'data' => ['risk' => 'low', 'score' => 5, 'findings' => []]]);

        $response = $this->actingAs($user)->getJson('/api/reports?type=security');

        $response->assertStatus(200)->assertJsonCount(1, 'reports')
            ->assertJsonPath('reports.0.type', 'security');
    }

    public function test_show_returns_full_data_to_owner_and_403_to_others(): void
    {
        $user = User::factory()->create();
        $report = $this->report($user);

        $this->actingAs($user)->getJson("/api/reports/{$report->id}")
            ->assertStatus(200)->assertJsonPath('data.grade', 'A');

        $this->actingAs(User::factory()->create())->getJson("/api/reports/{$report->id}")
            ->assertStatus(403);
    }

    public function test_share_creates_a_public_token_and_unauthenticated_read_works(): void
    {
        $user = User::factory()->create();
        $report = $this->report($user);

        $share = $this->actingAs($user)->postJson("/api/reports/{$report->id}/share");
        $share->assertStatus(200)->assertJsonPath('shared', true);
        $token = $share->json('share_token');
        $this->assertNotEmpty($token);

        // Public route: no authentication.
        $this->getJson("/api/reports/shared/{$token}")
            ->assertStatus(200)
            ->assertJsonPath('data.grade', 'A')
            ->assertJsonPath('shared', true);
    }

    public function test_revoking_share_disables_the_public_link(): void
    {
        $user = User::factory()->create();
        $report = $this->report($user);
        $token = $this->actingAs($user)->postJson("/api/reports/{$report->id}/share")->json('share_token');

        $this->actingAs($user)->deleteJson("/api/reports/{$report->id}/share")
            ->assertStatus(200)->assertJsonPath('shared', false);

        $this->getJson("/api/reports/shared/{$token}")->assertStatus(404);
    }

    public function test_compare_requires_matching_types(): void
    {
        $user = User::factory()->create();
        $a = $this->report($user, ['type' => 'conformance']);
        $b = $this->report($user, ['type' => 'security', 'data' => ['risk' => 'low', 'score' => 5, 'findings' => []]]);

        $this->actingAs($user)->getJson("/api/reports/compare?a={$a->id}&b={$b->id}")
            ->assertStatus(422);
    }

    public function test_compare_returns_both_reports(): void
    {
        $user = User::factory()->create();
        $a = $this->report($user, ['data' => ['grade' => 'B', 'score' => 80, 'checks' => []]]);
        $b = $this->report($user, ['data' => ['grade' => 'A', 'score' => 95, 'checks' => []]]);

        $this->actingAs($user)->getJson("/api/reports/compare?a={$a->id}&b={$b->id}")
            ->assertStatus(200)
            ->assertJsonPath('type', 'conformance')
            ->assertJsonPath('a.data.grade', 'B')
            ->assertJsonPath('b.data.grade', 'A');
    }

    public function test_owner_can_delete_a_report(): void
    {
        $user = User::factory()->create();
        $report = $this->report($user);

        $this->actingAs($user)->deleteJson("/api/reports/{$report->id}")->assertStatus(200);
        $this->assertDatabaseMissing('inspection_reports', ['id' => $report->id]);
    }

    public function test_reports_require_authentication(): void
    {
        $this->getJson('/api/reports')->assertStatus(401);
    }
}
