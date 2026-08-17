<?php

namespace Database\Seeders;

use App\Models\CatalogItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds a working Catalog for Spi: two connectors (the Spi MCP gateway and
 * SCX), plus the agents, skills, tools, prompts, and resources they expose.
 *
 * Two rules matter here:
 *
 *  1. Items belonging to a connector use the same slug convention as a real
 *     sync ("{connectorSlug}-{Str::slug(name)}", see
 *     ConnectorSyncController::import), so syncing that connector for real
 *     updates these rows in place instead of creating duplicates.
 *  2. Seeding is idempotent and never clobbers activation state — re-running
 *     it refreshes descriptions and schemas but leaves is_active as the admin
 *     set it, exactly as a re-sync does.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->connectors() as $connector) {
            $this->upsert('connector', Str::slug($connector['name']), $connector);
        }

        foreach ($this->items() as $item) {
            $connectorSlug = $item['connector'] ?? null;
            unset($item['connector']);

            $slug = $connectorSlug
                ? $connectorSlug.'-'.(Str::slug($item['name']) ?: 'item')
                : Str::slug($item['name']);

            if ($connectorSlug) {
                $connector = CatalogItem::where(['type' => 'connector', 'slug' => $connectorSlug])->first();
                $item['provider'] = $connector?->name ?? $item['provider'] ?? null;
                $item['metadata'] = array_merge($item['metadata'] ?? [], [
                    'connector_slug' => $connectorSlug,
                    // Never copy the connector's auth_header onto an item.
                    'endpoint' => $connector->metadata['endpoint'] ?? null,
                    'protocol' => $connector->metadata['protocol'] ?? 'mcp',
                ]);
            }

            $this->upsert($item['type'], $slug, $item);
        }

        $this->command?->info('Catalog seeded: '.CatalogItem::count().' items.');
    }

    /**
     * Create or refresh an item, preserving is_active on existing rows.
     */
    private function upsert(string $type, string $slug, array $attributes): void
    {
        $item = CatalogItem::firstOrNew(['type' => $type, 'slug' => $slug]);
        $isNew = ! $item->exists;

        $item->fill([
            'type' => $type,
            'slug' => $slug,
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'version' => $attributes['version'] ?? null,
            'provider' => $attributes['provider'] ?? null,
            'metadata' => $attributes['metadata'] ?? null,
        ]);

        // Activation is the admin's call; only seed a default on first insert.
        if ($isNew) {
            $item->is_active = $attributes['is_active'] ?? false;
        }

        $item->save();
    }

    /**
     * The registered servers. Endpoints must be publicly routable — the
     * admin UI re-validates them against the SSRF guard on create and sync.
     */
    private function connectors(): array
    {
        return [
            [
                'name' => 'Spi Gateway',
                'description' => 'Spi\'s own MCP server: request execution, inspection, and conformance tooling exposed over the Model Context Protocol.',
                'version' => '1.0.0',
                'provider' => 'apispi.com',
                'is_active' => true,
                'metadata' => [
                    'endpoint' => 'https://apispi.com/api/gateway/tools',
                    'protocol' => 'mcp',
                ],
            ],
            [
                'name' => 'SCX',
                'description' => 'SCX AI — the model provider behind Spi (the assistant) and the AI Lab authoring tools. Calls are billed to each user\'s own SCX API key, set in Profile.',
                'version' => '1.0.0',
                'provider' => 'scx.ai',
                'is_active' => true,
                'metadata' => [
                    'endpoint' => 'https://api.scx.ai/v1/chat/completions',
                    'protocol' => 'mcp',
                    // No auth_header: SCX is authenticated per user with their
                    // stored key, never with a shared workspace credential.
                ],
            ],
            [
                'name' => 'Spi Agent Network',
                'description' => 'A2A endpoint advertising Spi\'s agents and their skills via agent-card discovery.',
                'version' => '0.9.0',
                'provider' => 'apispi.com',
                'metadata' => [
                    'endpoint' => 'https://apispi.com/api/gateway/a2a',
                    'protocol' => 'a2a',
                ],
            ],
        ];
    }

    /**
     * Everything the connectors expose, plus the standalone agents.
     */
    private function items(): array
    {
        return array_merge(
            $this->agents(),
            $this->skills(),
            $this->tools(),
            $this->prompts(),
            $this->resources(),
        );
    }

    private function agents(): array
    {
        return [
            [
                'type' => 'agent',
                'connector' => 'spi-agent-network',
                'name' => 'Spi Inspector',
                'description' => 'Sends a request across any supported protocol, then explains the response — status, headers, payload shape, and what went wrong when it fails.',
                'version' => '1.0.0',
                'is_active' => true,
                'metadata' => [
                    'protocols' => ['rest', 'mcp', 'a2a', 'grpc', 'mqtt', 'amqp'],
                    'capabilities' => ['streaming' => false, 'pushNotifications' => false],
                ],
            ],
            [
                'type' => 'agent',
                'connector' => 'spi-agent-network',
                'name' => 'Spi Conformance Auditor',
                'description' => 'Grades an MCP server against the specification — handshake, protocol version, tool schemas, error handling — and reports a letter grade with findings.',
                'version' => '1.0.0',
                'is_active' => true,
                'metadata' => [
                    'protocols' => ['mcp'],
                    'capabilities' => ['streaming' => false, 'pushNotifications' => false],
                ],
            ],
            [
                'type' => 'agent',
                'connector' => 'spi-agent-network',
                'name' => 'Spi Security Scout',
                'description' => 'Scans MCP tool definitions for prompt-injection patterns, over-broad permissions, and instructions that try to exfiltrate context.',
                'version' => '0.9.0',
                'metadata' => [
                    'protocols' => ['mcp'],
                    'capabilities' => ['streaming' => false, 'pushNotifications' => false],
                ],
            ],
        ];
    }

    /**
     * Skills as an A2A agent card advertises them.
     */
    private function skills(): array
    {
        return [
            [
                'type' => 'skill',
                'connector' => 'spi-agent-network',
                'name' => 'Diagnose Failing Request',
                'description' => 'Given a request and its error, identify the cause and propose a corrected request.',
                'is_active' => true,
                'metadata' => [
                    'id' => 'diagnose-failing-request',
                    'tags' => ['debugging', 'http', 'mcp'],
                    'examples' => ['Why does this MCP call return -32601?', 'My POST returns 401 but the token is valid.'],
                ],
            ],
            [
                'type' => 'skill',
                'connector' => 'spi-agent-network',
                'name' => 'Author Request From Description',
                'description' => 'Turn a plain-English instruction into a runnable request for the chosen protocol.',
                'is_active' => true,
                'metadata' => [
                    'id' => 'author-request',
                    'tags' => ['authoring', 'rest', 'mcp', 'a2a'],
                    'examples' => ['Call the weather tool for Sydney tomorrow.', 'GET the third page of users, 50 per page.'],
                ],
            ],
            [
                'type' => 'skill',
                'connector' => 'spi-agent-network',
                'name' => 'Generate Response Assertions',
                'description' => 'Propose stable assertions for a response — status, field presence and types, invariants — avoiding brittle exact-value matches.',
                'metadata' => [
                    'id' => 'generate-assertions',
                    'tags' => ['testing', 'assertions'],
                    'examples' => ['Write assertions for this JSON response.'],
                ],
            ],
            [
                'type' => 'skill',
                'connector' => 'spi-agent-network',
                'name' => 'Review MCP Server',
                'description' => 'Read a server\'s tools, prompts, and resources and report conformance and security concerns.',
                'metadata' => [
                    'id' => 'review-mcp-server',
                    'tags' => ['mcp', 'security', 'conformance'],
                    'examples' => ['Review this MCP server before we connect it.'],
                ],
            ],
        ];
    }

    /**
     * Tools, with the inputSchema the tester reads to build a tools/call
     * template (ToolController@active exposes it as input_schema).
     */
    private function tools(): array
    {
        return [
            [
                'type' => 'tool',
                'connector' => 'spi-gateway',
                'name' => 'http_request',
                'description' => 'Send an HTTP request to a publicly routable URL and return status, headers, body, and elapsed time.',
                'is_active' => true,
                'metadata' => [
                    'inputSchema' => [
                        'type' => 'object',
                        'required' => ['url'],
                        'properties' => [
                            'url' => ['type' => 'string', 'description' => 'Target URL. Supports {{variable}} placeholders.'],
                            'method' => ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], 'default' => 'GET'],
                            'headers' => ['type' => 'object', 'description' => 'Request headers.'],
                            'body' => ['type' => 'string', 'description' => 'Raw request body.'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'tool',
                'connector' => 'spi-gateway',
                'name' => 'mcp_call',
                'description' => 'Call a method on any MCP server over Streamable HTTP, handling initialize and session negotiation.',
                'is_active' => true,
                'metadata' => [
                    'inputSchema' => [
                        'type' => 'object',
                        'required' => ['url', 'method'],
                        'properties' => [
                            'url' => ['type' => 'string', 'description' => 'MCP endpoint.'],
                            'method' => ['type' => 'string', 'description' => 'e.g. tools/list, tools/call, resources/read.'],
                            'params' => ['type' => 'object', 'description' => 'JSON-RPC params.'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'tool',
                'connector' => 'spi-gateway',
                'name' => 'grade_conformance',
                'description' => 'Grade an MCP server against the specification and return a letter grade with per-check findings.',
                'is_active' => true,
                'metadata' => [
                    'inputSchema' => [
                        'type' => 'object',
                        'required' => ['url'],
                        'properties' => [
                            'url' => ['type' => 'string'],
                            'strict' => ['type' => 'boolean', 'default' => false, 'description' => 'Fail optional checks rather than skipping them.'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'tool',
                'connector' => 'spi-gateway',
                'name' => 'scan_prompt_injection',
                'description' => 'Inspect MCP tool descriptions and schemas for prompt-injection and context-exfiltration patterns.',
                'metadata' => [
                    'inputSchema' => [
                        'type' => 'object',
                        'required' => ['url'],
                        'properties' => [
                            'url' => ['type' => 'string'],
                            'include_prompts' => ['type' => 'boolean', 'default' => true],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'tool',
                'connector' => 'spi-gateway',
                'name' => 'resolve_environment',
                'description' => 'Preview how {{variable}} placeholders resolve against an environment, without sending the request. Secret values come back masked.',
                'metadata' => [
                    'inputSchema' => [
                        'type' => 'object',
                        'required' => ['environment', 'payload'],
                        'properties' => [
                            'environment' => ['type' => 'string', 'description' => 'Environment name.'],
                            'payload' => ['type' => 'object', 'description' => 'Request payload containing placeholders.'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'tool',
                'connector' => 'scx',
                'name' => 'chat_completion',
                'description' => 'Generate a chat completion with SCX. Billed to the calling user\'s own SCX API key.',
                'is_active' => true,
                'metadata' => [
                    'inputSchema' => [
                        'type' => 'object',
                        'required' => ['messages'],
                        'properties' => [
                            'messages' => [
                                'type' => 'array',
                                'description' => 'Conversation so far.',
                                'items' => [
                                    'type' => 'object',
                                    'required' => ['role', 'content'],
                                    'properties' => [
                                        'role' => ['type' => 'string', 'enum' => ['system', 'user', 'assistant']],
                                        'content' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                            'model' => ['type' => 'string', 'default' => 'scx-ai'],
                            'temperature' => ['type' => 'number', 'default' => 0.7, 'minimum' => 0, 'maximum' => 2],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Prompts, with the `arguments` array PromptController@active exposes and
     * the tester turns into a prompts/get template.
     */
    private function prompts(): array
    {
        return [
            [
                'type' => 'prompt',
                'connector' => 'spi-gateway',
                'name' => 'debug_failing_request',
                'description' => 'Diagnose why a request failed and propose a corrected version.',
                'is_active' => true,
                'metadata' => [
                    'arguments' => [
                        ['name' => 'request', 'description' => 'The request that failed, as JSON.', 'required' => true],
                        ['name' => 'error', 'description' => 'Error message or response body.', 'required' => true],
                        ['name' => 'protocol', 'description' => 'rest, mcp, a2a, grpc, mqtt, or amqp.', 'required' => false],
                    ],
                ],
            ],
            [
                'type' => 'prompt',
                'connector' => 'spi-gateway',
                'name' => 'write_assertions',
                'description' => 'Generate stable assertions for a response body.',
                'is_active' => true,
                'metadata' => [
                    'arguments' => [
                        ['name' => 'response', 'description' => 'Response body to assert on.', 'required' => true],
                        ['name' => 'status', 'description' => 'HTTP status code.', 'required' => false],
                    ],
                ],
            ],
            [
                'type' => 'prompt',
                'connector' => 'spi-gateway',
                'name' => 'explain_response',
                'description' => 'Explain what a response means, field by field, and flag anything unexpected.',
                'metadata' => [
                    'arguments' => [
                        ['name' => 'response', 'description' => 'Response body.', 'required' => true],
                        ['name' => 'audience', 'description' => 'beginner or experienced.', 'required' => false],
                    ],
                ],
            ],
            [
                'type' => 'prompt',
                'connector' => 'scx',
                'name' => 'review_mcp_server',
                'description' => 'Review an MCP server\'s tools and prompts for conformance and security concerns.',
                'metadata' => [
                    'arguments' => [
                        ['name' => 'tools', 'description' => 'The server\'s tools/list output.', 'required' => true],
                        ['name' => 'focus', 'description' => 'security, conformance, or both.', 'required' => false],
                    ],
                ],
            ],
        ];
    }

    /**
     * Resources, with the uri/mimeType ResourceController@active exposes.
     */
    private function resources(): array
    {
        return [
            [
                'type' => 'resource',
                'connector' => 'spi-gateway',
                'name' => 'Protocol Reference',
                'description' => 'How Spi speaks each supported protocol, including transport details and limitations.',
                'is_active' => true,
                'metadata' => [
                    'uri' => 'spi://docs/protocols',
                    'mimeType' => 'text/markdown',
                ],
            ],
            [
                'type' => 'resource',
                'connector' => 'spi-gateway',
                'name' => 'Developer API Spec',
                'description' => 'OpenAPI description of the /api/v1 programmatic API.',
                'is_active' => true,
                'metadata' => [
                    'uri' => 'spi://docs/openapi.json',
                    'mimeType' => 'application/json',
                ],
            ],
            [
                'type' => 'resource',
                'connector' => 'spi-gateway',
                'name' => 'Environment Variable Guide',
                'description' => 'Using {{variable}} placeholders and secret masking across environments.',
                'metadata' => [
                    'uri' => 'spi://docs/environments',
                    'mimeType' => 'text/markdown',
                ],
            ],
        ];
    }
}
