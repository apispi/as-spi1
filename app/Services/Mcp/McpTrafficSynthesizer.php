<?php

namespace App\Services\Mcp;

use App\Models\McpProxy;
use App\Services\Contracts\SchemaInferrer;

/**
 * Reverse-engineers an MCP server's real contract from recorded flight-recorder
 * traffic.
 *
 * The novel output is the OBSERVED contract, which can differ from the one the
 * server declares: for each tool an agent actually called, infer the input
 * schema from the arguments that were really sent and the output schema from
 * the responses that really came back — then set that beside what tools/list
 * declared. "The server says this tool takes {query}, but agents send
 * {query, limit} and get back {results[], nextCursor}." No schema authoring;
 * it is learned from what happened.
 */
class McpTrafficSynthesizer
{
    public function __construct(private readonly SchemaInferrer $inferrer = new SchemaInferrer)
    {
    }

    public function synthesize(McpProxy $proxy): array
    {
        $declared = [];               // name => declared inputSchema (from tools/list)
        $callArgs = [];               // name => [argument sets]
        $callResults = [];            // name => [result payloads]

        foreach ($proxy->exchanges()->get() as $ex) {
            $method = $ex->method;
            $request = $ex->request ?? [];
            $response = $ex->response ?? [];

            if ($method === 'tools/list') {
                foreach ($response['result']['tools'] ?? [] as $tool) {
                    if (! empty($tool['name'])) {
                        $declared[$tool['name']] = $tool['inputSchema'] ?? null;
                    }
                }

                continue;
            }

            if ($method === 'tools/call') {
                $name = $request['params']['name'] ?? null;
                if (! is_string($name) || $name === '') {
                    continue;
                }

                $callArgs[$name][] = $request['params']['arguments'] ?? [];

                // Skip error and truncated responses when learning the output.
                if (isset($response['result']) && ! isset($response['truncated'])) {
                    $callResults[$name][] = $response['result'];
                }
            }
        }

        $names = array_values(array_unique(array_merge(
            array_keys($declared), array_keys($callArgs)
        )));
        sort($names);

        $tools = [];
        foreach ($names as $name) {
            $tools[] = [
                'name' => $name,
                'call_count' => count($callArgs[$name] ?? []),
                'declared_input_schema' => $declared[$name] ?? null,
                'observed_input_schema' => isset($callArgs[$name]) ? $this->inferrer->inferMany($callArgs[$name]) : null,
                'observed_output_schema' => isset($callResults[$name]) ? $this->inferrer->inferMany($callResults[$name]) : null,
                'only_observed' => ! array_key_exists($name, $declared),
            ];
        }

        return [
            'proxy' => ['id' => $proxy->id, 'name' => $proxy->name, 'upstream_url' => $proxy->upstream_url],
            'exchanges_seen' => $proxy->exchanges()->count(),
            'tools' => $tools,
            'generated_at' => now(),
        ];
    }
}
