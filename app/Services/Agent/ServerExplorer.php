<?php

namespace App\Services\Agent;

use App\Services\Mcp\McpSecurityScanner;

/**
 * Autonomous exploration of an MCP server: the SCX model pursues a goal by
 * calling the server's tools, and Spi records the path, the capabilities it
 * discovered, and — in safe mode — every destructive tool the agent reached
 * for and was stopped from calling.
 *
 * This is the agent loop turned into a red-team / discovery instrument:
 *  - safe mode refuses side-effecting tools, so pointing it at a real server
 *    is safe by default; a blocked call is a finding, not an execution;
 *  - the discovered tool surface is run through the injection scanner, so the
 *    exploration doubles as a security scan of what the agent was exposed to.
 */
class ServerExplorer extends AgentLoopRunner
{
    /** Names the model tried to call that safe mode refused. */
    private array $blocked = [];

    public function __construct(
        $mcp,
        $scx,
        int $maxSteps = 8,
        private readonly bool $safeMode = true,
    ) {
        parent::__construct($mcp, $scx, $maxSteps);
    }

    public function explore(string $goal): array
    {
        $trace = $this->run($goal);

        // The tool surface the model was handed (captured during the run).
        $scan = McpSecurityScanner::scan(array_map(fn ($t) => [
            'name' => $t['name'] ?? 'unnamed',
            'description' => $t['description'] ?? '',
            'schema' => $t['inputSchema'] ?? null,
        ], $this->toolList));

        $destructive = array_values(array_filter(
            array_map(fn ($t) => $t['name'] ?? null, $this->toolList),
            fn ($name) => $name && DestructiveHeuristic::isDestructive($name)
        ));

        // Which tools the agent actually invoked, from the trace.
        $attempted = [];
        foreach ($trace['steps'] as $step) {
            foreach ($step['tool_calls'] ?? [] as $call) {
                $attempted[$call['name']] = true;
            }
        }

        return $trace + [
            'safe_mode' => $this->safeMode,
            'capabilities' => [
                'risk' => $scan['risk'],
                'findings' => $scan['findings'],
                'destructive_tools' => $destructive,
            ],
            'blocked_attempts' => array_values($this->blocked),
            'tools_attempted' => array_keys($attempted),
        ];
    }

    /**
     * In safe mode, refuse a destructive tool instead of calling it — and hand
     * the model a message so it looks for a read-only path or concludes.
     */
    protected function executeToolCall(array $call): array
    {
        $name = $call['function']['name'] ?? 'unknown';
        $rawArgs = $call['function']['arguments'] ?? '{}';
        $args = is_array($rawArgs) ? $rawArgs : (json_decode((string) $rawArgs, true) ?: []);

        if ($this->safeMode && DestructiveHeuristic::isDestructive($name)) {
            $this->blocked[] = ['name' => $name, 'arguments' => $args];

            return [
                'name' => $name,
                'arguments' => $args,
                'is_error' => true,
                'blocked' => true,
                'error' => 'Blocked by safe exploration mode (looks destructive).',
                'result' => null,
                'result_text' => "BLOCKED: \"{$name}\" looks like it has side effects and was not called in safe exploration mode. "
                    .'Achieve the goal with read-only tools, or explain that it requires a destructive action.',
            ];
        }

        return parent::executeToolCall($call);
    }
}
