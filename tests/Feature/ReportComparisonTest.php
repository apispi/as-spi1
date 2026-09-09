<?php

namespace Tests\Feature;

use App\Models\InspectionReport;
use App\Models\User;
use App\Services\Reports\ReportComparer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportComparisonTest extends TestCase
{
    use RefreshDatabase;

    private function runData(array $steps, int $passed, int $total, int $timeMs = 100): array
    {
        return ['passed_count' => $passed, 'total' => $total, 'time_ms' => $timeMs, 'steps' => $steps];
    }

    private function report(User $user, array $data): InspectionReport
    {
        return InspectionReport::create(['user_id' => $user->id, 'type' => 'collection_run', 'summary' => 'run', 'data' => $data]);
    }

    public function test_it_classifies_regressed_fixed_and_unchanged_steps(): void
    {
        $comparer = new ReportComparer;

        $a = $this->runData([
            ['index' => 0, 'name' => 'Login', 'status' => 200, 'time_ms' => 50, 'passed' => true],
            ['index' => 1, 'name' => 'Fetch', 'status' => 200, 'time_ms' => 80, 'passed' => true],
            ['index' => 2, 'name' => 'Flaky', 'status' => 500, 'time_ms' => 30, 'passed' => false],
        ], 2, 3);

        $b = $this->runData([
            ['index' => 0, 'name' => 'Login', 'status' => 200, 'time_ms' => 60, 'passed' => true],  // unchanged
            ['index' => 1, 'name' => 'Fetch', 'status' => 500, 'time_ms' => 90, 'passed' => false], // regressed
            ['index' => 2, 'name' => 'Flaky', 'status' => 200, 'time_ms' => 40, 'passed' => true],  // fixed
        ], 2, 3);

        $diff = $comparer->collectionRun($a, $b);

        $this->assertSame(1, $diff['regressed_count']);
        $this->assertSame(1, $diff['fixed_count']);
        $verdicts = collect($diff['steps'])->pluck('verdict', 'name')->all();
        $this->assertSame('unchanged', $verdicts['Login']);
        $this->assertSame('regressed', $verdicts['Fetch']);
        $this->assertSame('fixed', $verdicts['Flaky']);
        // Timing delta is reported.
        $fetch = collect($diff['steps'])->firstWhere('name', 'Fetch');
        $this->assertSame(10, $fetch['time_delta_ms']);
        $this->assertTrue($fetch['status_changed']);
    }

    public function test_added_and_removed_steps(): void
    {
        $comparer = new ReportComparer;
        $a = $this->runData([['index' => 0, 'name' => 'Old', 'passed' => true]], 1, 1);
        $b = $this->runData([
            ['index' => 0, 'name' => 'Old', 'passed' => true],
            ['index' => 1, 'name' => 'New', 'passed' => true],
        ], 2, 2);

        $diff = $comparer->collectionRun($a, $b);
        $this->assertSame('added', collect($diff['steps'])->firstWhere('name', 'New')['verdict']);
    }

    public function test_the_endpoint_returns_a_diff_for_collection_runs(): void
    {
        $user = User::factory()->create();
        $a = $this->report($user, $this->runData([['index' => 0, 'name' => 'S', 'status' => 200, 'passed' => true]], 1, 1));
        $b = $this->report($user, $this->runData([['index' => 0, 'name' => 'S', 'status' => 500, 'passed' => false]], 0, 1));

        $res = $this->actingAs($user)->getJson("/api/reports/compare?a={$a->id}&b={$b->id}")
            ->assertOk()
            ->assertJsonPath('diff.regressed_count', 1);
        $this->assertStringContainsString('regressed', $res->json('headline'));
    }

    public function test_mismatched_types_are_rejected(): void
    {
        $user = User::factory()->create();
        $a = $this->report($user, $this->runData([], 0, 0));
        $b = InspectionReport::create(['user_id' => $user->id, 'type' => 'security', 'summary' => 's', 'data' => []]);

        $this->actingAs($user)->getJson("/api/reports/compare?a={$a->id}&b={$b->id}")->assertStatus(422);
    }

    public function test_compare_is_workspace_scoped(): void
    {
        $owner = User::factory()->create();
        $a = $this->report($owner, $this->runData([], 0, 0));
        $b = $this->report($owner, $this->runData([], 0, 0));

        $this->actingAs(User::factory()->create())
            ->getJson("/api/reports/compare?a={$a->id}&b={$b->id}")->assertStatus(404);
    }
}
