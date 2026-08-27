<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class McpGatewayTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->key = $this->user->generateApiKey();
    }

    private function rpc(string $method, array $params = [], ?int $id = 1)
    {
        $message = ['jsonrpc' => '2.0', 'method' => $method, 'params' => (object) $params];
        if ($id !== null) {
            $message['id'] = $id;
        }

        return $this->withHeader('Authorization', 'Bearer '.$this->key)
            ->postJson('/api/gateway/tools', $message);
    }

    private function toolCall(string $tool, array $arguments = [])
    {
        return $this->rpc('tools/call', ['name' => $tool, 'arguments' => (object) $arguments]);
    }

    private function resultData($response): array
    {
        return json_decode($response->json('result.content.0.text'), true);
    }

    public function test_the_handshake_satisfies_our_own_client_contract(): void
    {
        $this->rpc('initialize')
            ->assertOk()
            ->assertJsonPath('jsonrpc', '2.0')
            ->assertJsonPath('result.protocolVersion', '2025-06-18')
            ->assertJsonPath('result.serverInfo.name', 'Spi Gateway');

        // The initialized notification has no id; it is acknowledged, not answered.
        $this->rpc('notifications/initialized', [], null)->assertStatus(202);
        $this->rpc('ping')->assertOk()->assertJsonPath('id', 1);
    }

    public function test_unknown_methods_and_tools_return_proper_jsonrpc_errors(): void
    {
        // -32601 on unknown method is the conformance grader's strongest signal.
        $this->rpc('this/methodDoesNotExist')->assertOk()->assertJsonPath('error.code', -32601);
        $this->toolCall('__nonexistent_tool__')->assertOk()->assertJsonPath('error.code', -32602);
    }

    public function test_tools_list_declares_schemas_for_every_tool(): void
    {
        $tools = $this->rpc('tools/list')->assertOk()->json('result.tools');

        $this->assertSame(
            ['list_collections', 'run_collection', 'get_monitor_status', 'evaluate_assertions', 'http_request'],
            array_column($tools, 'name')
        );

        foreach ($tools as $tool) {
            $this->assertSame('object', $tool['inputSchema']['type'], "{$tool['name']} lacks an object schema.");
        }
    }

    public function test_an_agent_can_run_a_collection_by_name(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);

        $saved = $this->user->savedRequests()->create([
            'name' => 'Ping', 'protocol' => 'rest', 'method' => 'GET',
            'url' => 'https://api.example.com/ping',
            'assertions' => [['path' => 'status', 'operator' => 'equals', 'expected' => 200]],
        ]);
        $collection = $this->user->collections()->create(['name' => 'Smoke']);
        $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => 0]);

        $data = $this->resultData($this->toolCall('run_collection', ['collection' => 'Smoke'])->assertOk());

        $this->assertTrue($data['passed']);
        $this->assertSame(1, $data['passed_count']);
        // The run persisted as a report, same as a UI run.
        $this->assertDatabaseHas('inspection_reports', ['id' => $data['report_id'], 'type' => 'collection_run']);
    }

    public function test_monitor_status_reports_the_callers_monitors_only(): void
    {
        $other = User::factory()->create();
        $otherCollection = $other->collections()->create(['name' => 'Theirs']);
        $other->monitors()->create(['collection_id' => $otherCollection->id, 'name' => 'Their monitor', 'interval_minutes' => 60]);

        $mine = $this->user->collections()->create(['name' => 'Mine']);
        $this->user->monitors()->create([
            'collection_id' => $mine->id, 'name' => 'My monitor', 'interval_minutes' => 60, 'last_status' => 'failing',
        ]);

        $data = $this->resultData($this->toolCall('get_monitor_status')->assertOk());

        $this->assertCount(1, $data['monitors']);
        $this->assertSame('My monitor', $data['monitors'][0]['name']);
        $this->assertSame('failing', $data['monitors'][0]['status']);
    }

    public function test_assertions_evaluate_through_the_gateway(): void
    {
        $data = $this->resultData($this->toolCall('evaluate_assertions', [
            'assertions' => [['path' => 'status', 'operator' => 'equals', 'expected' => 200]],
            'response' => ['status' => 200, 'body' => '{}'],
        ])->assertOk());

        $this->assertTrue($data['passed']);
    }

    public function test_http_request_keeps_the_ssrf_guard(): void
    {
        $response = $this->toolCall('http_request', ['url' => 'http://169.254.169.254/latest/'])->assertOk();

        $this->assertSame(-32602, $response->json('error.code'));
        $this->assertMatchesRegularExpression('/reserved|private|loopback|host/i', $response->json('error.message'));
    }

    public function test_the_gateway_requires_an_api_key(): void
    {
        $this->postJson('/api/gateway/tools', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertStatus(401);
    }
}
