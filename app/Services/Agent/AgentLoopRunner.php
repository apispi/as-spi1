<?php

namespace App\Services\Agent;

use App\Services\Mcp\McpClient;
use App\Services\Scx\ScxClient;
use Throwable;

/**
 * Drives an MCP server the way a real agent would: it hands the server's tools
 * to the SCX model as callable functions, lets the model pursue a goal by
 * choosing and invoking tools, executes each call against the live server, and
 * feeds results back until the model declares it done or a step budget is hit.
 *
 * The recorded trace answers the question a plain tools/call cannot: is this
 * server actually *usable by an agent* — are the tools discoverable, are their
 * schemas followable, do results let the model make progress?
 */
class AgentLoopRunner
{
    public function __construct(
        protected McpClient $mcp,
        protected ScxClient $scx,
        protected int $maxSteps = 8,
    ) {
    }

    /**
     * @return array{goal:string,steps:array<int,array>,final_answer:?string,tool_call_count:int,completed:bool,stop_reason:string,tools_available:int}
     */
    public function run(string $goal): array
    {
        $init = $this->mcp->initialize();
        $server = trim(($init['serverInfo']['name'] ?? '').' '.($init['serverInfo']['version'] ?? '')) ?: 'the MCP server';

        $toolDefs = $this->buildToolDefinitions();

        $messages = [
            ['role' => 'system', 'content' =>
                "You are an agent connected to {$server} via MCP. Accomplish the user's goal using the available tools. "
                ."Call tools when you need information or actions; when the goal is met, reply with a final answer and no tool call. "
                ."Be efficient — do not call tools you do not need.",
            ],
            ['role' => 'user', 'content' => $goal],
        ];

        $steps = [];
        $toolCallCount = 0;
        $completed = false;
        $finalAnswer = null;
        $stopReason = 'max_steps';

        for ($step = 1; $step <= $this->maxSteps; $step++) {
            try {
                $message = $this->scx->chat($messages, [
                    'tools' => $toolDefs,
                    'temperature' => 0.1,
                    'max_tokens' => 1200,
                ]);
            } catch (Throwable $e) {
                $stopReason = 'model_error';
                $steps[] = ['step' => $step, 'assistant_text' => null, 'tool_calls' => [], 'error' => $e->getMessage()];
                break;
            }

            $assistantText = $message['content'] ?? null;
            $toolCalls = $message['tool_calls'] ?? [];

            // Record the assistant turn verbatim so subsequent tool messages
            // reference the right tool_call_ids.
            $messages[] = array_filter([
                'role' => 'assistant',
                'content' => $assistantText,
                'tool_calls' => $toolCalls ?: null,
            ], fn ($v) => $v !== null);

            if (empty($toolCalls)) {
                $completed = true;
                $finalAnswer = $assistantText;
                $stopReason = 'completed';
                $steps[] = ['step' => $step, 'assistant_text' => $assistantText, 'tool_calls' => []];
                break;
            }

            $executed = [];
            foreach ($toolCalls as $call) {
                $toolCallCount++;
                $record = $this->executeToolCall($call);
                $executed[] = $record;

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'] ?? ('call_'.$step),
                    'content' => $record['result_text'],
                ];
            }

            $steps[] = ['step' => $step, 'assistant_text' => $assistantText, 'tool_calls' => $executed];
        }

        return [
            'goal' => $goal,
            'server' => $server,
            'steps' => $steps,
            'final_answer' => $finalAnswer,
            'tool_call_count' => $toolCallCount,
            'completed' => $completed,
            'stop_reason' => $stopReason,
            'tools_available' => count($toolDefs),
        ];
    }

    /**
     * Execute one model-requested tool call against the MCP server, capturing
     * arguments, the result, and any error for the trace.
     */
    private function executeToolCall(array $call): array
    {
        $name = $call['function']['name'] ?? 'unknown';
        $rawArgs = $call['function']['arguments'] ?? '{}';
        $args = is_array($rawArgs) ? $rawArgs : (json_decode((string) $rawArgs, true) ?: []);

        $record = ['name' => $name, 'arguments' => $args, 'is_error' => false, 'error' => null, 'result' => null];

        try {
            $result = $this->mcp->callTool($name, $args);
            $record['result'] = $result;
            $record['is_error'] = (bool) ($result['isError'] ?? false);
            $record['result_text'] = $this->flattenResult($result);
        } catch (Throwable $e) {
            $record['is_error'] = true;
            $record['error'] = $e->getMessage();
            $record['result_text'] = 'Tool error: '.$e->getMessage();
        }

        return $record;
    }

    /**
     * MCP tool results are a content array of typed parts; flatten text parts
     * into a single string the model can read, falling back to JSON.
     */
    private function flattenResult(array $result): string
    {
        $texts = [];
        foreach ($result['content'] ?? [] as $part) {
            if (($part['type'] ?? null) === 'text' && isset($part['text'])) {
                $texts[] = $part['text'];
            }
        }

        if ($texts !== []) {
            return implode("\n", $texts);
        }

        return json_encode($result, JSON_UNESCAPED_SLASHES) ?: '(empty result)';
    }

    /**
     * Convert MCP tools/list into OpenAI-style function tool definitions.
     */
    private function buildToolDefinitions(): array
    {
        $tools = $this->mcp->listTools()['tools'] ?? [];
        $defs = [];

        foreach ($tools as $tool) {
            if (empty($tool['name'])) {
                continue;
            }
            $defs[] = [
                'type' => 'function',
                'function' => array_filter([
                    'name' => $tool['name'],
                    'description' => $tool['description'] ?? null,
                    'parameters' => $tool['inputSchema'] ?? ['type' => 'object', 'properties' => (object) []],
                ], fn ($v) => $v !== null),
            ];
        }

        return $defs;
    }
}
