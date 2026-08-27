<?php

namespace Tests\Unit\Services;

use App\Services\Mcp\McpPolicyEngine;
use PHPUnit\Framework\TestCase;

class McpPolicyEngineTest extends TestCase
{
    private McpPolicyEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new McpPolicyEngine;
    }

    private function call(string $tool, array $args = []): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args]];
    }

    public function test_it_blocks_a_tool_by_pattern(): void
    {
        $policy = [['action' => 'block', 'direction' => 'request', 'tool' => '/^delete_/']];

        $blocked = $this->engine->evaluateRequest($policy, $this->call('delete_user', ['id' => 1]));
        $this->assertSame('block', $blocked['action']);

        $allowed = $this->engine->evaluateRequest($policy, $this->call('search_users'));
        $this->assertSame('allow', $allowed['action']);
    }

    public function test_it_redacts_secret_arguments_by_key_or_value(): void
    {
        $policy = [['action' => 'redact', 'direction' => 'request', 'pattern' => '/(api[_-]?key|token|secret)/i']];

        $result = $this->engine->evaluateRequest($policy, $this->call('call_api', [
            'api_key' => 'sk-live-123',     // key matches
            'note' => 'my secret is here',  // value matches
            'page' => 2,                    // untouched (not a string / no match)
        ]));

        $this->assertSame(2, $result['redactions']);
        $this->assertSame(McpPolicyEngine::MASK, $result['arguments']['api_key']);
        $this->assertSame(McpPolicyEngine::MASK, $result['arguments']['note']);
        $this->assertSame(2, $result['arguments']['page']);
    }

    public function test_only_tool_calls_are_evaluated_on_the_request_side(): void
    {
        $policy = [['action' => 'block', 'direction' => 'request', 'tool' => '/.*/']];

        $result = $this->engine->evaluateRequest($policy, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]);
        $this->assertSame('allow', $result['action']);
    }

    public function test_it_blocks_an_injection_flagged_response(): void
    {
        $policy = [['action' => 'block', 'direction' => 'response', 'on_injection' => true]];

        $blocked = $this->engine->evaluateResponse($policy, ['result' => ['x' => 1]], flagged: true);
        $this->assertSame('block', $blocked['action']);

        $allowed = $this->engine->evaluateResponse($policy, ['result' => ['x' => 1]], flagged: false);
        $this->assertSame('allow', $allowed['action']);
    }

    public function test_it_redacts_response_values_by_pattern(): void
    {
        $policy = [['action' => 'redact', 'direction' => 'response', 'pattern' => '/ssn|social/i']];

        $result = $this->engine->evaluateResponse($policy, [
            'result' => ['ssn' => '111-22-3333', 'name' => 'Ada'],
        ], flagged: false);

        $this->assertSame('redact', $result['action']);
        $this->assertSame(McpPolicyEngine::MASK, $result['result']['result']['ssn']);
        $this->assertSame('Ada', $result['result']['result']['name']);
    }

    public function test_the_first_block_wins_over_later_rules(): void
    {
        $policy = [
            ['action' => 'block', 'direction' => 'request', 'tool' => '/pay/'],
            ['action' => 'redact', 'direction' => 'request', 'pattern' => '/amount/'],
        ];

        $result = $this->engine->evaluateRequest($policy, $this->call('pay_invoice', ['amount' => 100]));
        $this->assertSame('block', $result['action']);
    }

    public function test_validate_rejects_bad_rules(): void
    {
        $this->assertNotNull(McpPolicyEngine::validate([['action' => 'nuke', 'direction' => 'request']]));
        $this->assertNotNull(McpPolicyEngine::validate([['action' => 'redact', 'direction' => 'request']])); // no pattern
        $this->assertNotNull(McpPolicyEngine::validate([['action' => 'block', 'direction' => 'request', 'tool' => '/[unclosed/']]));
        $this->assertNull(McpPolicyEngine::validate([['action' => 'block', 'direction' => 'request', 'tool' => 'delete']]));
    }
}
