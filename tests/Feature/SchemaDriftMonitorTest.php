<?php

namespace Tests\Feature;

use App\Models\InspectionReport;
use App\Models\Monitor;
use App\Models\User;
use App\Services\Monitors\MonitorRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SchemaDriftMonitorTest extends TestCase
{
    use RefreshDatabase;

    private function graphqlSchema(array $fieldNames): array
    {
        return [
            'queryType' => ['name' => 'Query'],
            'types' => [[
                'kind' => 'OBJECT',
                'name' => 'User',
                'fields' => array_map(fn ($name) => [
                    'name' => $name,
                    'type' => ['kind' => 'SCALAR', 'name' => 'String'],
                    'args' => [],
                ], $fieldNames),
            ]],
        ];
    }

    /**
     * What the fake endpoint currently returns.
     *
     * A single stub reading this, rather than a fresh Http::fake() per change:
     * calling fake() again appends to the stub list instead of replacing it,
     * so the first registration keeps winning and the "new" schema is never
     * actually served.
     */
    private mixed $served = null;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['api.example.com/*' => function () {
            return $this->served instanceof \Illuminate\Http\Client\Response
                ? $this->served
                : Http::response($this->served, 200);
        }]);
    }

    private function serveGraphql(array $fieldNames): void
    {
        $this->served = ['data' => ['__schema' => $this->graphqlSchema($fieldNames)]];
    }

    private function openApi(array $paths): array
    {
        return [
            'openapi' => '3.0.3',
            'info' => ['title' => 'Widgets', 'version' => '1.0.0'],
            'paths' => array_fill_keys($paths, ['get' => ['responses' => ['200' => []]]]),
        ];
    }

    private function monitor(User $user, string $type, string $url): Monitor
    {
        return $user->monitors()->create([
            'name' => 'Upstream schema',
            'type' => $type,
            'target_url' => $url,
            'interval_minutes' => 60,
        ]);
    }

    private function tick(Monitor $monitor)
    {
        return app(MonitorRunner::class)->run($monitor->fresh());
    }

    // -------------------------------------------------------------- baseline

    public function test_the_first_run_captures_a_baseline_and_passes(): void
    {
        $this->serveGraphql(['id', 'email']);
        $user = User::factory()->create();
        $monitor = $this->monitor($user, Monitor::TYPE_GRAPHQL_DRIFT, 'https://api.example.com/graphql');

        $result = $this->tick($monitor);

        $this->assertTrue($result->passed);
        $this->assertSame('Baseline captured.', $result->summary);

        $report = InspectionReport::find($result->inspection_report_id);
        $this->assertSame('schema_drift', $report->type);
        $this->assertNotNull($report->data['document'], 'The baseline must be kept to compare against.');
    }

    public function test_an_unchanged_schema_passes_without_re_storing_it(): void
    {
        $this->serveGraphql(['id', 'email']);
        $user = User::factory()->create();
        $monitor = $this->monitor($user, Monitor::TYPE_GRAPHQL_DRIFT, 'https://api.example.com/graphql');

        $this->tick($monitor);
        $result = $this->tick($monitor);

        $this->assertTrue($result->passed);
        $this->assertSame('No schema change.', $result->summary);

        // A hundred identical copies of a schema is not history.
        $this->assertNull(InspectionReport::find($result->inspection_report_id)->data['document']);
    }

    public function test_a_reordered_schema_is_not_reported_as_drift(): void
    {
        // A server that shuffles its type list between responses must not look
        // like a change every single run.
        $user = User::factory()->create();
        $monitor = $this->monitor($user, Monitor::TYPE_GRAPHQL_DRIFT, 'https://api.example.com/graphql');

        $this->serveGraphql(['id', 'email']);
        $this->tick($monitor);

        $this->serveGraphql(['email', 'id']);
        $result = $this->tick($monitor);

        $this->assertTrue($result->passed);
        $this->assertSame('No schema change.', $result->summary);
    }

    // --------------------------------------------------------------- drifting

    public function test_a_removed_field_fails_the_monitor(): void
    {
        $user = User::factory()->create();
        $monitor = $this->monitor($user, Monitor::TYPE_GRAPHQL_DRIFT, 'https://api.example.com/graphql');

        $this->serveGraphql(['id', 'email']);
        $this->tick($monitor);

        $this->serveGraphql(['id']);
        $result = $this->tick($monitor);

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('Breaking schema change', $result->summary);
        $this->assertStringContainsString('User.email', $result->summary);

        $data = InspectionReport::find($result->inspection_report_id)->data;
        $this->assertSame(1, $data['diff']['breaking_count']);
        $this->assertNotNull($data['document'], 'The new schema becomes the baseline.');
    }

    public function test_an_additive_change_is_recorded_but_does_not_fail(): void
    {
        // Paging somebody because a third party added a field is how
        // monitoring gets muted.
        $user = User::factory()->create();
        $monitor = $this->monitor($user, Monitor::TYPE_GRAPHQL_DRIFT, 'https://api.example.com/graphql');

        $this->serveGraphql(['id']);
        $this->tick($monitor);

        $this->serveGraphql(['id', 'email']);
        $result = $this->tick($monitor);

        $this->assertTrue($result->passed);
        $this->assertStringContainsString('Schema changed', $result->summary);
        $this->assertStringNotContainsString('Breaking', $result->summary);
    }

    public function test_the_new_schema_becomes_the_baseline_so_one_break_alerts_once(): void
    {
        $user = User::factory()->create();
        $monitor = $this->monitor($user, Monitor::TYPE_GRAPHQL_DRIFT, 'https://api.example.com/graphql');

        $this->serveGraphql(['id', 'email']);
        $this->tick($monitor);

        $this->serveGraphql(['id']);
        $this->assertFalse($this->tick($monitor)->passed);

        // Same (broken) schema on the next tick: nothing new to report.
        $third = $this->tick($monitor);
        $this->assertTrue($third->passed);
        $this->assertSame('No schema change.', $third->summary);
    }

    // ------------------------------------------------------------- unreachable

    public function test_an_unreachable_endpoint_fails_without_losing_the_baseline(): void
    {
        $user = User::factory()->create();
        $monitor = $this->monitor($user, Monitor::TYPE_GRAPHQL_DRIFT, 'https://api.example.com/graphql');

        $this->serveGraphql(['id', 'email']);
        $this->tick($monitor);

        $this->served = Http::response('gateway down', 502);
        $down = $this->tick($monitor);
        $this->assertFalse($down->passed);
        $this->assertStringContainsString('Unreachable', $down->summary);

        // Back up, unchanged: the baseline survived the outage, so this is not
        // reported as a fresh baseline capture.
        $this->serveGraphql(['id', 'email']);
        $recovered = $this->tick($monitor);
        $this->assertTrue($recovered->passed);
        $this->assertSame('No schema change.', $recovered->summary);
    }

    public function test_disabled_introspection_is_reported_as_unreachable(): void
    {
        $this->served = ['errors' => [['message' => 'introspection is disabled']]];

        $user = User::factory()->create();
        $monitor = $this->monitor($user, Monitor::TYPE_GRAPHQL_DRIFT, 'https://api.example.com/graphql');

        $result = $this->tick($monitor);

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('introspection is disabled', $result->summary);
    }

    // ----------------------------------------------------------------- openapi

    public function test_an_openapi_document_is_watched_the_same_way(): void
    {
        $user = User::factory()->create();
        $monitor = $this->monitor($user, Monitor::TYPE_OPENAPI_DRIFT, 'https://api.example.com/openapi.json');

        $this->served = $this->openApi(['/widgets', '/gadgets']);
        $this->assertSame('Baseline captured.', $this->tick($monitor)->summary);

        $this->served = $this->openApi(['/widgets']);
        $result = $this->tick($monitor);

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('Breaking schema change', $result->summary);
        $this->assertStringContainsString('GET /gadgets', $result->summary);
    }

    public function test_a_url_that_is_not_an_openapi_document_is_reported(): void
    {
        $this->served = ['hello' => 'world'];

        $user = User::factory()->create();
        $monitor = $this->monitor($user, Monitor::TYPE_OPENAPI_DRIFT, 'https://api.example.com/openapi.json');

        $this->assertStringContainsString(
            'did not return an OpenAPI 3 document',
            $this->tick($monitor)->summary
        );
    }

    // ------------------------------------------------------------- management

    public function test_a_schema_monitor_can_be_created_through_the_api(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/monitors', [
            'name' => 'Upstream GraphQL',
            'type' => Monitor::TYPE_GRAPHQL_DRIFT,
            'target_url' => 'https://api.example.com/graphql',
            'interval_minutes' => 60,
        ])->assertCreated()->assertJsonPath('type', Monitor::TYPE_GRAPHQL_DRIFT);

        $this->assertDatabaseHas('monitors', ['type' => Monitor::TYPE_GRAPHQL_DRIFT]);
    }

    public function test_a_schema_monitor_needs_a_target_url_and_no_collection(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/monitors', [
            'name' => 'Missing target',
            'type' => Monitor::TYPE_OPENAPI_DRIFT,
            'interval_minutes' => 60,
        ])->assertStatus(422)->assertJsonValidationErrors('target_url');
    }

    public function test_an_internal_target_is_refused_by_the_ssrf_guard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/monitors', [
            'name' => 'Internal',
            'type' => Monitor::TYPE_GRAPHQL_DRIFT,
            'target_url' => 'http://127.0.0.1/graphql',
            'interval_minutes' => 60,
        ])->assertStatus(422)->assertJsonValidationErrors('target_url');
    }
}
