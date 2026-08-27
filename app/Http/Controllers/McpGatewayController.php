<?php

namespace App\Http\Controllers;

use App\Models\InspectionReport;
use App\Services\Assertions\AssertionEvaluator;
use App\Services\Collections\CollectionRunner;
use App\Services\Collections\RequestExecutor;
use Illuminate\Http\Request;
use Throwable;

/**
 * Spi's own MCP server: the Streamable HTTP endpoint the seeded "Spi Gateway"
 * connector has always advertised, now real.
 *
 * The inversion is the point — every MCP server out there is something agents
 * test WITH; this one makes Spi the testing tool agents USE. The tools are the
 * caller's own artefacts: run a collection, check monitors, evaluate
 * assertions, send a guarded HTTP request. Authenticated with the same
 * personal API key as /api/v1, so an agent is scoped to its owner's data.
 *
 * Stateless by design (the spec permits it): no session is issued, and every
 * request stands alone. Responses are plain JSON, which the Streamable HTTP
 * transport allows in place of SSE.
 */
class McpGatewayController extends Controller
{
    public const PROTOCOL_VERSION = '2025-06-18';

    public function __construct(
        private readonly CollectionRunner $runner,
        private readonly AssertionEvaluator $evaluator,
        private readonly RequestExecutor $executor,
    ) {
    }

    public function handle(Request $request)
    {
        $message = $request->json()->all();

        if (($message['jsonrpc'] ?? null) !== '2.0' || empty($message['method'])) {
            return $this->error(null, -32600, 'Invalid Request: expected a JSON-RPC 2.0 message.');
        }

        $id = $message['id'] ?? null;
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        // A message without an id is a notification; acknowledge and stop.
        if ($id === null) {
            return response()->noContent(202);
        }

        try {
            $result = match ($message['method']) {
                'initialize' => $this->initialize(),
                'ping' => (object) [],
                'tools/list' => ['tools' => $this->toolDefinitions()],
                'tools/call' => $this->callTool($request, $params),
                default => null,
            };
        } catch (GatewayToolException $e) {
            return $this->error($id, (int) $e->getCode(), $e->getMessage());
        } catch (Throwable $e) {
            return $this->error($id, -32603, 'Internal error: '.$e->getMessage());
        }

        if ($result === null) {
            return $this->error($id, -32601, 'Method not found: '.$message['method']);
        }

        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function initialize(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => ['tools' => (object) []],
            'serverInfo' => ['name' => 'Spi Gateway', 'version' => '1.0.0'],
        ];
    }

    /**
     * The MCP tool surface. Every schema is a plain object schema so any
     * client can render a form for it — including Spi's own tester.
     */
    private function toolDefinitions(): array
    {
        return [
            [
                'name' => 'list_collections',
                'description' => 'List your collections and their steps, so you can pick one to run.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
            ],
            [
                'name' => 'run_collection',
                'description' => 'Run one of your collections and return the per-step results, including assertion outcomes. The run is saved as a report.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['collection'],
                    'properties' => [
                        'collection' => ['type' => 'string', 'description' => 'Collection name or id.'],
                        'environment' => ['type' => 'string', 'description' => 'Environment name or id to resolve {{variables}} against.'],
                    ],
                ],
            ],
            [
                'name' => 'get_monitor_status',
                'description' => 'Your monitors with current status, uptime, and consecutive failures. Pass a name to get one monitor with its recent history.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'monitor' => ['type' => 'string', 'description' => 'Monitor name or id (optional).'],
                    ],
                ],
            ],
            [
                'name' => 'evaluate_assertions',
                'description' => 'Evaluate assertions against a response you already have. Operators: equals, not_equals, contains, not_contains, matches, exists, not_exists, greater_than, greater_or_equal, less_than, less_or_equal, is_type, has_length.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['assertions', 'response'],
                    'properties' => [
                        'assertions' => ['type' => 'array', 'description' => 'Rows of {path, operator, expected}.'],
                        'response' => ['type' => 'object', 'description' => '{status, time_ms, headers, body}.'],
                    ],
                ],
            ],
            [
                'name' => 'http_request',
                'description' => 'Send an HTTP request to a publicly routable URL and get status, headers, body, and timing back. Private and internal addresses are refused.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['url'],
                    'properties' => [
                        'url' => ['type' => 'string'],
                        'method' => ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], 'default' => 'GET'],
                        'headers' => ['type' => 'object'],
                        'body' => ['type' => 'string'],
                    ],
                ],
            ],
        ];
    }

    private function callTool(Request $request, array $params): array
    {
        $name = $params['name'] ?? '';
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $user = $request->user();

        $data = match ($name) {
            'list_collections' => $this->listCollections($user),
            'run_collection' => $this->runCollection($user, $arguments),
            'get_monitor_status' => $this->monitorStatus($user, $arguments),
            'evaluate_assertions' => $this->evaluate($arguments),
            'http_request' => $this->httpRequest($arguments),
            default => throw new GatewayToolException("Unknown tool: {$name}", -32602),
        };

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ]],
            'isError' => false,
        ];
    }

    private function listCollections($user): array
    {
        return [
            'collections' => \App\Models\Collection::inWorkspaceOf($user)->with('steps.savedRequest:id,name,protocol,method')
                ->orderBy('name')->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'description' => $c->description,
                    'steps' => $c->steps->map(fn ($s) => $s->savedRequest?->name)->filter()->values(),
                ]),
        ];
    }

    private function runCollection($user, array $arguments): array
    {
        $selector = (string) ($arguments['collection'] ?? '');

        $collection = is_numeric($selector)
            ? \App\Models\Collection::inWorkspaceOf($user)->find((int) $selector)
            : \App\Models\Collection::inWorkspaceOf($user)->where('name', $selector)->first();

        if (! $collection) {
            throw new GatewayToolException("No collection matches \"{$selector}\".", -32602);
        }

        if ($collection->steps()->count() === 0) {
            throw new GatewayToolException('That collection has no steps to run.', -32602);
        }

        $environment = null;
        if (($env = (string) ($arguments['environment'] ?? '')) !== '') {
            $environment = is_numeric($env)
                ? \App\Models\Environment::inWorkspaceOf($user)->find((int) $env)
                : \App\Models\Environment::inWorkspaceOf($user)->where('name', $env)->first();

            if (! $environment) {
                throw new GatewayToolException("No environment matches \"{$env}\".", -32602);
            }
        } else {
            $environment = $user->environments()->where('is_default', true)->first();
        }

        $result = $this->runner->run($collection, $environment);

        // Same contract as the HTTP run endpoint: every run persists as a
        // shareable, diffable report.
        $report = InspectionReport::create([
            'user_id' => $user->id,
            'type' => 'collection_run',
            'summary' => sprintf('%s — %d/%d passed (via MCP gateway)', $collection->name, $result['passed_count'], $result['total']),
            'data' => $result,
        ]);

        return $result + ['report_id' => $report->id];
    }

    private function monitorStatus($user, array $arguments): array
    {
        $selector = (string) ($arguments['monitor'] ?? '');

        if ($selector !== '') {
            $monitor = is_numeric($selector)
                ? \App\Models\Monitor::inWorkspaceOf($user)->find((int) $selector)
                : \App\Models\Monitor::inWorkspaceOf($user)->where('name', $selector)->first();

            if (! $monitor) {
                throw new GatewayToolException("No monitor matches \"{$selector}\".", -32602);
            }

            return [
                'monitor' => $this->presentMonitor($monitor),
                'recent_results' => $monitor->results()->take(10)
                    ->get(['passed', 'time_ms', 'passed_count', 'total', 'summary', 'created_at']),
            ];
        }

        return [
            'monitors' => \App\Models\Monitor::inWorkspaceOf($user)->with('collection:id,name')->orderBy('name')->get()
                ->map(fn ($m) => $this->presentMonitor($m)),
        ];
    }

    private function presentMonitor($monitor): array
    {
        return [
            'id' => $monitor->id,
            'name' => $monitor->name,
            'collection' => $monitor->collection?->name,
            'status' => $monitor->last_status,
            'is_enabled' => $monitor->is_enabled,
            'last_run_at' => $monitor->last_run_at,
            'consecutive_failures' => $monitor->consecutive_failures,
            'uptime' => $monitor->uptime(),
        ];
    }

    private function evaluate(array $arguments): array
    {
        if (! is_array($arguments['assertions'] ?? null) || ! is_array($arguments['response'] ?? null)) {
            throw new GatewayToolException('evaluate_assertions needs `assertions` (array) and `response` (object).', -32602);
        }

        return $this->evaluator->evaluate($arguments['assertions'], $arguments['response']);
    }

    private function httpRequest(array $arguments): array
    {
        $result = $this->executor->send([
            'protocol' => 'rest',
            'method' => strtoupper((string) ($arguments['method'] ?? 'GET')),
            'url' => (string) ($arguments['url'] ?? ''),
            'headers' => is_array($arguments['headers'] ?? null) ? $arguments['headers'] : [],
            'body' => $arguments['body'] ?? null,
        ]);

        if (! $result['ok']) {
            throw new GatewayToolException($result['error'] ?? 'Request failed.', -32602);
        }

        return [
            'status' => $result['status'],
            'time_ms' => $result['time_ms'],
            'headers' => $result['headers'],
            'body' => $result['body'],
        ];
    }

    private function error(mixed $id, int $code, string $message)
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }
}

/**
 * A tool-level failure with a JSON-RPC error code attached.
 */
class GatewayToolException extends \RuntimeException
{
    public function __construct(string $message, int $code = -32602)
    {
        parent::__construct($message, $code);
    }
}
