<?php

namespace Tests\Feature;

use App\Models\InspectionReport;
use App\Models\User;
use App\Services\Grpc\GrpcClient;
use App\Services\Mqtt\MqttTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CollectionRunnerTest extends TestCase
{
    use RefreshDatabase;

    private function saved(User $user, array $attributes = []): \App\Models\SavedRequest
    {
        return $user->savedRequests()->create(array_merge([
            'name' => 'Step',
            'protocol' => 'rest',
            'method' => 'GET',
            'url' => 'https://api.example.com/one',
        ], $attributes));
    }

    private function collection(User $user, array $steps, array $attributes = []): \App\Models\Collection
    {
        $collection = $user->collections()->create(array_merge([
            'name' => 'Suite',
        ], $attributes));

        foreach ($steps as $position => $step) {
            $collection->steps()->create([
                'saved_request_id' => $step['id'],
                'position' => $position,
                'extract' => $step['extract'] ?? [],
            ]);
        }

        return $collection;
    }

    public function test_it_runs_steps_in_order_and_reports_a_pass(): void
    {
        Http::fake([
            'api.example.com/one' => Http::response(['id' => 1], 200),
            'api.example.com/two' => Http::response(['id' => 2], 200),
        ]);

        $user = User::factory()->create();
        $a = $this->saved($user, ['name' => 'First', 'assertions' => [
            ['path' => 'status', 'operator' => 'equals', 'expected' => 200],
        ]]);
        $b = $this->saved($user, ['name' => 'Second', 'url' => 'https://api.example.com/two']);

        $collection = $this->collection($user, [['id' => $a->id], ['id' => $b->id]]);

        $response = $this->actingAs($user)->postJson("/api/collections/{$collection->id}/run");

        $response->assertOk()
            ->assertJsonPath('passed', true)
            ->assertJsonPath('total', 2)
            ->assertJsonPath('passed_count', 2)
            ->assertJsonPath('steps.0.name', 'First')
            ->assertJsonPath('steps.1.name', 'Second');
    }

    public function test_a_value_extracted_from_one_step_feeds_the_next(): void
    {
        Http::fake([
            'api.example.com/login' => Http::response(['token' => 'tok-123'], 200),
            'api.example.com/me' => Http::response(['ok' => true], 200),
        ]);

        $user = User::factory()->create();
        $login = $this->saved($user, ['name' => 'Login', 'url' => 'https://api.example.com/login']);
        $me = $this->saved($user, [
            'name' => 'Me',
            'url' => 'https://api.example.com/me',
            'headers' => ['Authorization' => 'Bearer {{token}}'],
        ]);

        $collection = $this->collection($user, [
            ['id' => $login->id, 'extract' => [['name' => 'token', 'path' => 'token']]],
            ['id' => $me->id],
        ]);

        $this->actingAs($user)->postJson("/api/collections/{$collection->id}/run")
            ->assertOk()
            ->assertJsonPath('steps.0.extracted', ['token']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/me')
            && $request->header('Authorization')[0] === 'Bearer tok-123');
    }

    public function test_a_failing_assertion_fails_the_run_and_skips_the_rest(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['id' => 1], 500)]);

        $user = User::factory()->create();
        $a = $this->saved($user, ['name' => 'Boom', 'assertions' => [
            ['path' => 'status', 'operator' => 'equals', 'expected' => 200],
        ]]);
        $b = $this->saved($user, ['name' => 'Never runs', 'url' => 'https://api.example.com/two']);

        $collection = $this->collection($user, [['id' => $a->id], ['id' => $b->id]]);

        // 422 so a CI caller can gate on the status code alone.
        $this->actingAs($user)->postJson("/api/collections/{$collection->id}/run")
            ->assertStatus(422)
            ->assertJsonPath('passed', false)
            ->assertJsonPath('failed_count', 1)
            ->assertJsonPath('skipped_count', 1)
            ->assertJsonPath('steps.1.skipped', true);
    }

    public function test_continue_on_failure_runs_every_step(): void
    {
        Http::fake(['api.example.com/*' => Http::response([], 500)]);

        $user = User::factory()->create();
        $a = $this->saved($user, ['name' => 'One', 'assertions' => [
            ['path' => 'status', 'operator' => 'equals', 'expected' => 200],
        ]]);
        $b = $this->saved($user, ['name' => 'Two', 'url' => 'https://api.example.com/two']);

        $collection = $this->collection($user, [['id' => $a->id], ['id' => $b->id]], [
            'continue_on_failure' => true,
        ]);

        $this->actingAs($user)->postJson("/api/collections/{$collection->id}/run")
            ->assertStatus(422)
            ->assertJsonPath('skipped_count', 0)
            ->assertJsonPath('steps.1.skipped', false);
    }

    public function test_environment_variables_resolve_per_step(): void
    {
        Http::fake(['api.staging.example.com/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create();
        $env = $user->environments()->create([
            'name' => 'Staging',
            'variables' => [['key' => 'host', 'value' => 'api.staging.example.com', 'secret' => false]],
        ]);
        $step = $this->saved($user, ['url' => 'https://{{host}}/one']);
        $collection = $this->collection($user, [['id' => $step->id]]);

        $this->actingAs($user)->postJson("/api/collections/{$collection->id}/run", [
            'environment_id' => $env->id,
        ])->assertOk()->assertJsonPath('environment.name', 'Staging');

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://api.staging.example.com/'));
    }

    public function test_secret_values_are_masked_in_the_persisted_run(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create();
        $env = $user->environments()->create([
            'name' => 'Prod',
            'variables' => [['key' => 'key', 'value' => 'super-secret-value', 'secret' => true]],
        ]);
        $step = $this->saved($user, ['url' => 'https://api.example.com/one?k={{key}}']);
        $collection = $this->collection($user, [['id' => $step->id]]);

        $response = $this->actingAs($user)->postJson("/api/collections/{$collection->id}/run", [
            'environment_id' => $env->id,
        ])->assertOk();

        // The real value reaches the target, but a run is a shareable report.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'super-secret-value'));
        $this->assertStringNotContainsString('super-secret-value', $response->getContent());

        $report = InspectionReport::where('type', 'collection_run')->firstOrFail();
        $this->assertStringNotContainsString('super-secret-value', json_encode($report->data));
    }

    public function test_a_run_is_saved_as_a_report(): void
    {
        Http::fake(['api.example.com/*' => Http::response([], 200)]);

        $user = User::factory()->create();
        $step = $this->saved($user);
        $collection = $this->collection($user, [['id' => $step->id]]);

        $this->actingAs($user)->postJson("/api/collections/{$collection->id}/run")->assertOk();

        $report = InspectionReport::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('collection_run', $report->type);
        $this->assertStringContainsString('1/1 passed', $report->summary);
    }

    public function test_the_ssrf_guard_applies_to_every_step(): void
    {
        $user = User::factory()->create();
        $step = $this->saved($user, ['url' => 'http://169.254.169.254/latest/meta-data/']);
        $collection = $this->collection($user, [['id' => $step->id]]);

        $this->actingAs($user)->postJson("/api/collections/{$collection->id}/run")
            ->assertStatus(422)
            ->assertJsonPath('steps.0.passed', false);
    }

    /** Bind a fake MQTT tester and capture the options it is run with. */
    private function fakeMqtt(?array &$captured = null): void
    {
        $this->app->instance(MqttTester::class, new class($captured) extends MqttTester
        {
            public function __construct(private &$captured)
            {
                parent::__construct();
            }

            public function run(array $opts): array
            {
                $this->captured = $opts;

                return [
                    'broker' => $opts['host'].':'.($opts['port'] ?? 1883),
                    'action' => $opts['action'],
                    'topic' => $opts['topic'] ?? '',
                    'published' => true,
                    'messages' => [['topic' => $opts['topic'] ?? '', 'message' => 'pong']],
                    'message_count' => 1,
                ];
            }
        });
    }

    public function test_a_broker_step_runs_and_its_result_can_be_asserted_on(): void
    {
        $this->fakeMqtt();

        $user = User::factory()->create();
        $step = $this->saved($user, [
            'name' => 'Publish',
            'protocol' => 'mqtt',
            'method' => 'publish',
            'url' => 'broker.example.com',
            'params' => ['host' => 'broker.example.com', 'action' => 'publish', 'topic' => 'sensors/temp'],
            // The whole tester result is the body, so protocol-level outcomes
            // are asserted on it.
            'assertions' => [
                ['path' => 'published', 'operator' => 'equals', 'expected' => 'true'],
                ['path' => 'message_count', 'operator' => 'equals', 'expected' => 1],
                ['path' => 'messages.0.message', 'operator' => 'equals', 'expected' => 'pong'],
            ],
        ]);
        $collection = $this->collection($user, [['id' => $step->id]]);

        $this->actingAs($user)->postJson("/api/collections/{$collection->id}/run")
            ->assertOk()
            ->assertJsonPath('passed', true)
            ->assertJsonPath('steps.0.assertions.passed_count', 3);
    }

    public function test_broker_credentials_resolve_from_the_environment_and_are_masked(): void
    {
        $captured = null;
        $this->fakeMqtt($captured);

        $user = User::factory()->create();
        $env = $user->environments()->create([
            'name' => 'Prod',
            'variables' => [
                ['key' => 'broker', 'value' => 'broker.example.com', 'secret' => false],
                ['key' => 'broker_password', 'value' => 'super-secret-value', 'secret' => true],
            ],
        ]);

        $step = $this->saved($user, [
            'name' => 'Publish',
            'protocol' => 'mqtt',
            'method' => 'publish',
            'url' => '{{broker}}',
            'params' => [
                'host' => '{{broker}}',
                'action' => 'publish',
                'topic' => 'sensors/temp',
                'username' => 'sensor',
                'password' => '{{broker_password}}',
            ],
        ]);
        $collection = $this->collection($user, [['id' => $step->id]]);

        $response = $this->actingAs($user)->postJson("/api/collections/{$collection->id}/run", [
            'environment_id' => $env->id,
        ])->assertOk();

        // The broker receives the real credential...
        $this->assertSame('broker.example.com', $captured['host']);
        $this->assertSame('super-secret-value', $captured['password']);

        // ...but a run is a shareable report, so it must not appear there.
        $this->assertStringNotContainsString('super-secret-value', $response->getContent());
    }

    public function test_an_unsafe_broker_host_is_refused_before_connecting(): void
    {
        $this->app->instance(MqttTester::class, new class extends MqttTester
        {
            public function run(array $opts): array
            {
                throw new \RuntimeException('A connection was attempted for an unsafe host.');
            }
        });

        $user = User::factory()->create();
        $step = $this->saved($user, [
            'protocol' => 'mqtt',
            'url' => '127.0.0.1',
            'params' => ['host' => '127.0.0.1', 'action' => 'publish', 'topic' => 'x'],
        ]);
        $collection = $this->collection($user, [['id' => $step->id]]);

        $this->actingAs($user)->postJson("/api/collections/{$collection->id}/run")
            ->assertStatus(422)
            ->assertJsonPath('steps.0.passed', false);
    }

    public function test_an_empty_collection_is_rejected(): void
    {
        $user = User::factory()->create();
        $collection = $user->collections()->create(['name' => 'Empty']);

        $this->actingAs($user)->postJson("/api/collections/{$collection->id}/run")
            ->assertStatus(422);
    }

    public function test_it_runs_over_the_v1_api_with_a_key(): void
    {
        Http::fake(['api.example.com/*' => Http::response([], 200)]);

        $user = User::factory()->create();
        $key = $user->generateApiKey();
        $step = $this->saved($user);
        $collection = $this->collection($user, [['id' => $step->id]]);

        $this->withHeader('Authorization', 'Bearer '.$key)
            ->postJson("/api/v1/collections/{$collection->id}/run")
            ->assertOk()
            ->assertJsonPath('passed', true);
    }

    public function test_a_user_cannot_run_another_users_collection(): void
    {
        $owner = User::factory()->create();
        $step = $this->saved($owner);
        $collection = $this->collection($owner, [['id' => $step->id]]);

        $this->actingAs(User::factory()->create())
            ->postJson("/api/collections/{$collection->id}/run")
            ->assertStatus(404);
    }

    public function test_a_grpc_step_runs_and_exposes_its_status_for_assertions(): void
    {
        $captured = null;
        $this->app->instance(GrpcClient::class, new class($captured) extends GrpcClient
        {
            public function __construct(private &$captured)
            {
                parent::__construct();
            }

            public function unary(array $opts): array
            {
                $this->captured = $opts;

                return [
                    'ok' => true,
                    'method' => $opts['service_method'],
                    'http_status' => 200,
                    'grpc_status' => 0,
                    'grpc_status_name' => 'OK',
                    'response' => ['message' => 'hello'],
                    'metadata' => ['content-type' => 'application/grpc'],
                ];
            }
        });

        $user = User::factory()->create();
        $step = $this->saved($user, [
            'name' => 'Greet',
            'protocol' => 'grpc',
            // The saved request keeps the service/method in `method`; params
            // may omit it, so the executor falls back to that.
            'method' => 'helloworld.Greeter/SayHello',
            'url' => 'grpc.example.com',
            'params' => ['host' => 'grpc.example.com', 'request' => []],
            'assertions' => [
                ['path' => 'grpc_status', 'operator' => 'equals', 'expected' => 0],
                ['path' => 'response.message', 'operator' => 'equals', 'expected' => 'hello'],
            ],
        ]);
        $collection = $this->collection($user, [['id' => $step->id]]);

        $this->actingAs($user)->postJson("/api/collections/{$collection->id}/run")
            ->assertOk()
            ->assertJsonPath('passed', true)
            ->assertJsonPath('steps.0.assertions.passed_count', 2);

        $this->assertSame('helloworld.Greeter/SayHello', $captured['service_method']);
    }
}
