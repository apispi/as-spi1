<?php

namespace App\Services\Mcp;

use Throwable;

/**
 * Runs a synced MCP connector through a suite of protocol-conformance checks
 * and grades how faithfully it implements the spec — beyond "is it up". Each
 * check yields pass / warn / fail / skip with a human-readable detail; the
 * overall grade is a weighted score mapped to a letter (A–F).
 *
 * Spec reference: https://modelcontextprotocol.io/specification
 */
class McpConformanceGrader
{
    /** JSON-RPC reserved codes we assert against. */
    private const METHOD_NOT_FOUND = -32601;

    private const INVALID_PARAMS = -32602;

    public function __construct(protected McpClient $client)
    {
    }

    /**
     * @return array{grade:string,score:int,server:?string,protocol_version:?string,checks:array<int,array>}
     */
    public function run(): array
    {
        $checks = [];
        $init = null;

        // 1. Initialize handshake — the foundational check. If it fails, most
        //    downstream checks are meaningless, so we short-circuit.
        try {
            $init = $this->client->initialize();
            $hasServerInfo = ! empty($init['serverInfo']['name']);
            $checks[] = $this->check(
                'initialize', 'Initialize handshake', 5,
                $hasServerInfo ? 'pass' : 'warn',
                $hasServerInfo
                    ? 'Server completed the handshake and identified itself.'
                    : 'Handshake succeeded but serverInfo.name is missing.'
            );
        } catch (Throwable $e) {
            $checks[] = $this->check('initialize', 'Initialize handshake', 5, 'fail', 'Handshake failed: '.$e->getMessage());

            return $this->grade($checks, null, null);
        }

        // 2. Protocol version — must echo a version string.
        $version = $init['protocolVersion'] ?? null;
        $checks[] = $this->check(
            'protocol_version', 'Declares a protocol version', 3,
            $version ? 'pass' : 'fail',
            $version ? "Reported protocol version {$version}." : 'No protocolVersion in the initialize result.'
        );

        // 3. Capabilities object — spec requires a capabilities member.
        $capabilities = $init['capabilities'] ?? null;
        $checks[] = $this->check(
            'capabilities', 'Advertises a capabilities object', 2,
            is_array($capabilities) ? 'pass' : 'warn',
            is_array($capabilities)
                ? 'Advertised capabilities: '.(implode(', ', array_keys($capabilities)) ?: 'none').'.'
                : 'initialize did not return a capabilities object.'
        );

        // 4. tools/list — the core MCP surface.
        $tools = [];
        try {
            $tools = $this->client->listTools()['tools'] ?? [];
            $checks[] = $this->check('tools_list', 'tools/list returns a tool array', 4, 'pass', 'Returned '.count($tools).' tool(s).');
        } catch (Throwable $e) {
            $checks[] = $this->check('tools_list', 'tools/list returns a tool array', 4, 'fail', 'tools/list failed: '.$e->getMessage());
        }

        // 5. Tools declare an input schema (recommended, aids agent use).
        if ($tools) {
            $withSchema = count(array_filter($tools, fn ($t) => ! empty($t['inputSchema'])));
            $checks[] = $this->check(
                'tool_schemas', 'Tools declare input schemas', 2,
                $withSchema === count($tools) ? 'pass' : ($withSchema > 0 ? 'warn' : 'fail'),
                "{$withSchema}/".count($tools).' tool(s) declare an inputSchema.'
            );
        } else {
            $checks[] = $this->check('tool_schemas', 'Tools declare input schemas', 2, 'skip', 'No tools to inspect.');
        }

        // 6. Unknown method → -32601 Method not found. Strong conformance signal.
        $checks[] = $this->errorCodeCheck(
            'unknown_method', 'Unknown method returns -32601', 4,
            fn () => $this->client->rawRequest('this/methodDoesNotExist'),
            self::METHOD_NOT_FOUND
        );

        // 7. Unknown tool → an error (ideally -32602 invalid params, but any
        //    JSON-RPC error or an isError tool result is acceptable).
        try {
            $msg = $this->client->rawRequest('tools/call', ['name' => '__nonexistent_tool__', 'arguments' => (object) []]);
            $isError = isset($msg['error']) || ! empty($msg['result']['isError']);
            $code = $msg['error']['code'] ?? null;
            $checks[] = $this->check(
                'unknown_tool', 'Calling an unknown tool errors cleanly', 3,
                $isError ? 'pass' : 'fail',
                $isError
                    ? ($code ? "Rejected with JSON-RPC error {$code}." : 'Rejected via an isError tool result.')
                    : 'Server did not signal an error for an unknown tool.'
            );
        } catch (Throwable $e) {
            // A thrown protocol error still counts as rejecting cleanly.
            $checks[] = $this->check('unknown_tool', 'Calling an unknown tool errors cleanly', 3, 'pass', 'Rejected: '.$e->getMessage());
        }

        // 8. Capability honesty — if a capability is advertised, its list
        //    method must work; if not advertised, we don't penalise absence.
        $checks[] = $this->capabilityHonesty('resources', 'resources/list', fn () => $this->client->listResources(), $capabilities);
        $checks[] = $this->capabilityHonesty('prompts', 'prompts/list', fn () => $this->client->listPrompts(), $capabilities);

        return $this->grade($checks, trim(($init['serverInfo']['name'] ?? '').' '.($init['serverInfo']['version'] ?? '')) ?: null, $version);
    }

    /**
     * Assert that invoking $call yields a JSON-RPC error with $expectedCode.
     */
    private function errorCodeCheck(string $id, string $label, int $weight, callable $call, int $expectedCode): array
    {
        try {
            $msg = $call();
            $code = $msg['error']['code'] ?? null;

            if ($code === $expectedCode) {
                return $this->check($id, $label, $weight, 'pass', "Returned the expected error code {$expectedCode}.");
            }
            if (isset($msg['error'])) {
                return $this->check($id, $label, $weight, 'warn', "Errored, but with code {$code} instead of {$expectedCode}.");
            }

            return $this->check($id, $label, $weight, 'fail', 'Did not return a JSON-RPC error.');
        } catch (Throwable $e) {
            return $this->check($id, $label, $weight, 'warn', 'Transport error while probing: '.$e->getMessage());
        }
    }

    /**
     * If $capName is advertised in capabilities, its list method must succeed;
     * if it isn't advertised, absence is fine (skip).
     */
    private function capabilityHonesty(string $capName, string $label, callable $call, ?array $capabilities): array
    {
        $advertised = is_array($capabilities) && array_key_exists($capName, $capabilities);

        if (! $advertised) {
            return $this->check($capName.'_honesty', "{$label} matches capabilities", 2, 'skip', ucfirst($capName).' capability not advertised.');
        }

        try {
            $call();

            return $this->check($capName.'_honesty', "{$label} matches capabilities", 2, 'pass', ucfirst($capName).' advertised and '.$label.' works.');
        } catch (Throwable $e) {
            return $this->check($capName.'_honesty', "{$label} matches capabilities", 2, 'fail', ucfirst($capName)." advertised but {$label} failed: ".$e->getMessage());
        }
    }

    private function check(string $id, string $label, int $weight, string $status, string $detail): array
    {
        return compact('id', 'label', 'weight', 'status', 'detail');
    }

    /**
     * Weighted scoring: pass=1, warn=0.5, fail=0; skips are excluded from the
     * denominator so a server isn't punished for optional features it omits.
     */
    private function grade(array $checks, ?string $server, ?string $version): array
    {
        $scoreMap = ['pass' => 1.0, 'warn' => 0.5, 'fail' => 0.0];
        $earned = 0.0;
        $possible = 0.0;

        foreach ($checks as $c) {
            if ($c['status'] === 'skip') {
                continue;
            }
            $earned += $c['weight'] * ($scoreMap[$c['status']] ?? 0);
            $possible += $c['weight'];
        }

        $pct = $possible > 0 ? (int) round($earned / $possible * 100) : 0;

        return [
            'grade' => $this->letter($pct),
            'score' => $pct,
            'server' => $server,
            'protocol_version' => $version,
            'checks' => $checks,
        ];
    }

    private function letter(int $pct): string
    {
        return match (true) {
            $pct >= 97 => 'A+',
            $pct >= 90 => 'A',
            $pct >= 80 => 'B',
            $pct >= 70 => 'C',
            $pct >= 60 => 'D',
            default => 'F',
        };
    }
}
