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

    public function test_index_filters_by_collection_run_type(): void
    {
        // Regression: collection_run (and the other non-scan types) used to be
        // rejected by the type validation, breaking the list filter.
        $user = User::factory()->create();
        InspectionReport::create(['user_id' => $user->id, 'type' => 'collection_run', 'summary' => 'Smoke — 3/3 passed', 'data' => []]);
        $this->report($user, ['type' => 'conformance']);

        $this->actingAs($user)->getJson('/api/reports?type=collection_run')
            ->assertStatus(200)->assertJsonCount(1, 'reports')
            ->assertJsonPath('reports.0.type', 'collection_run');
    }

    public function test_index_searches_summaries(): void
    {
        $user = User::factory()->create();
        InspectionReport::create(['user_id' => $user->id, 'type' => 'collection_run', 'summary' => 'Checkout suite — 5/5 passed', 'data' => []]);
        InspectionReport::create(['user_id' => $user->id, 'type' => 'collection_run', 'summary' => 'Login suite — 2/3 passed', 'data' => []]);

        $res = $this->actingAs($user)->getJson('/api/reports?q=checkout')
            ->assertStatus(200)->assertJsonCount(1, 'reports');
        $this->assertStringContainsString('Checkout', $res->json('reports.0.summary'));
    }

    public function test_index_filters_by_a_rolling_date_window(): void
    {
        $user = User::factory()->create();
        $recent = InspectionReport::create(['user_id' => $user->id, 'type' => 'collection_run', 'summary' => 'today', 'data' => []]);
        $old = InspectionReport::create(['user_id' => $user->id, 'type' => 'collection_run', 'summary' => 'old', 'data' => []]);
        $old->forceFill(['created_at' => now()->subDays(40)])->save();

        // Last 7 days → only the recent one.
        $this->actingAs($user)->getJson('/api/reports?days=7')
            ->assertOk()->assertJsonCount(1, 'reports')
            ->assertJsonPath('reports.0.id', $recent->id);

        // Any time (no window) → both.
        $this->actingAs($user)->getJson('/api/reports')->assertJsonCount(2, 'reports');

        // An unsupported window is rejected.
        $this->actingAs($user)->getJson('/api/reports?days=3')->assertStatus(422);
    }

    public function test_index_rejects_an_unknown_type(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/reports?type=dragon')
            ->assertStatus(422)->assertJsonValidationErrors(['type']);
    }

    public function test_a_report_exports_as_json(): void
    {
        $user = User::factory()->create();
        $report = $this->report($user);

        $res = $this->actingAs($user)->get("/api/reports/{$report->id}/export?format=json")
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="report-'.$report->id.'.json"');

        $this->assertStringContainsString('application/json', $res->headers->get('Content-Type'));
        $this->assertSame('A', $res->json('data.grade'));
    }

    public function test_a_report_exports_as_markdown(): void
    {
        $user = User::factory()->create();
        $report = InspectionReport::create([
            'user_id' => $user->id, 'type' => 'collection_run',
            'summary' => 'Smoke — 2/3 passed',
            'data' => ['passed_count' => 2, 'total' => 3, 'steps' => [['name' => 'Login', 'passed' => true]]],
        ]);

        $res = $this->actingAs($user)->get("/api/reports/{$report->id}/export?format=md")
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="report-'.$report->id.'.md"');

        $body = $res->getContent();
        $this->assertStringContainsString('# Collection run', $body);
        $this->assertStringContainsString('Smoke — 2/3 passed', $body);
        $this->assertStringContainsString('Login', $body);
    }

    public function test_export_is_workspace_scoped(): void
    {
        $owner = User::factory()->create();
        $report = $this->report($owner);

        $this->actingAs(User::factory()->create())
            ->get("/api/reports/{$report->id}/export")->assertStatus(403);
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
