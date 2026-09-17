<?php

namespace Tests\Feature;

use App\Models\InspectionReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JUnitExportTest extends TestCase
{
    use RefreshDatabase;

    private function collection(User $user, string $url = 'https://api.example.com/users')
    {
        $saved = $user->savedRequests()->create([
            'name' => 'List users', 'protocol' => 'rest', 'method' => 'GET', 'url' => $url,
        ]);
        $collection = $user->collections()->create(['name' => 'Smoke suite']);
        $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => 0]);

        return $collection;
    }

    public function test_a_run_can_return_junit_xml_directly(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create();
        $collection = $this->collection($user);

        $response = $this->actingAs($user)
            ->post("/api/collections/{$collection->id}/run", ['format' => 'junit']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $response->assertHeader('X-Spi-Passed', 'true');

        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml);
        $this->assertSame('1', (string) $xml->testsuite['tests']);
        $this->assertSame('0', (string) $xml->testsuite['failures']);
        $this->assertSame('GET List users', (string) $xml->testsuite->testcase[0]['name']);
    }

    public function test_a_failing_run_still_returns_200_so_ci_can_publish_the_artifact(): void
    {
        // A non-2xx would make `curl -f` discard the very file the pipeline
        // wants to publish — the failures live inside the document instead.
        Http::fake(['api.example.com/*' => Http::response(['error' => 'nope'], 500)]);

        $user = User::factory()->create();
        $collection = $this->collection($user);
        $collection->steps()->first()->savedRequest->update([
            'assertions' => [['path' => 'status', 'operator' => 'equals', 'expected' => '200']],
        ]);

        $response = $this->actingAs($user)
            ->post("/api/collections/{$collection->id}/run", ['format' => 'junit']);

        $response->assertOk();
        $response->assertHeader('X-Spi-Passed', 'false');

        $xml = simplexml_load_string($response->getContent());
        $this->assertSame('1', (string) $xml->testsuite['failures']);
        $this->assertStringContainsString('assertion(s) failed', (string) $xml->testsuite->testcase[0]->failure['message']);
    }

    public function test_the_json_form_is_unchanged_when_no_format_is_asked_for(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create();
        $collection = $this->collection($user);

        $this->actingAs($user)->postJson("/api/collections/{$collection->id}/run")
            ->assertOk()
            ->assertJsonPath('passed', true)
            ->assertJsonStructure(['report_id', 'steps']);
    }

    public function test_a_dataset_run_can_return_junit_xml(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create();
        $collection = $this->collection($user, 'https://api.example.com/users/{{id}}');

        $response = $this->actingAs($user)->post("/api/collections/{$collection->id}/run-dataset", [
            'format' => 'junit',
            'dataset' => [['id' => 1], ['id' => 2]],
        ]);

        $response->assertOk();
        $xml = simplexml_load_string($response->getContent());
        $this->assertSame('2', (string) $xml->testsuite['tests']);
        $this->assertSame('Row 1', (string) $xml->testsuite->testcase[0]['name']);
    }

    public function test_a_stored_run_report_exports_as_junit(): void
    {
        $user = User::factory()->create();
        $report = InspectionReport::create([
            'user_id' => $user->id,
            'type' => 'collection_run',
            'summary' => 'Smoke suite — 1/1 passed',
            'data' => [
                'passed' => true,
                'collection' => ['id' => 1, 'name' => 'Smoke suite'],
                'environment' => null,
                'time_ms' => 100,
                'steps' => [[
                    'index' => 0, 'name' => 'List users', 'method' => 'GET',
                    'url' => 'https://api.example.com/users', 'status' => 200,
                    'time_ms' => 90, 'error' => null, 'unresolved' => [],
                    'assertions' => null, 'passed' => true, 'skipped' => false,
                ]],
            ],
        ]);

        $response = $this->actingAs($user)->get("/api/reports/{$report->id}/export?format=junit");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $this->assertStringContainsString('report-'.$report->id.'.junit.xml', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('<testsuites', $response->getContent());
    }

    public function test_junit_export_is_refused_for_a_non_run_report(): void
    {
        $user = User::factory()->create();
        $report = InspectionReport::create([
            'user_id' => $user->id,
            'type' => 'api_diff',
            'summary' => 'Widgets API: no breaking changes',
            'data' => ['breaking' => false, 'changes' => []],
        ]);

        $this->actingAs($user)->getJson("/api/reports/{$report->id}/export?format=junit")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'run reports only'));
    }

    public function test_the_other_export_formats_still_work(): void
    {
        $user = User::factory()->create();
        $report = InspectionReport::create([
            'user_id' => $user->id,
            'type' => 'security',
            'summary' => 'No findings',
            'data' => ['risk' => 'low', 'score' => 100, 'findings' => []],
        ]);

        $this->actingAs($user)->get("/api/reports/{$report->id}/export?format=md")
            ->assertOk()->assertHeader('Content-Type', 'text/markdown; charset=UTF-8');

        $this->actingAs($user)->get("/api/reports/{$report->id}/export")
            ->assertOk()->assertHeader('Content-Type', 'application/json');
    }
}
