<?php

namespace App\Services\Catalog;

use App\Models\CatalogItem;
use App\Rules\PubliclyRoutableUrl;
use App\Services\Mcp\McpClient;
use Illuminate\Support\Facades\Validator;

/**
 * Resolves a stored connector CatalogItem into a live, ready-to-use client,
 * re-validating its endpoint against the SSRF guard at call time (DNS may have
 * changed since it was saved). Shared by the conformance grader, the security
 * scanner, and the agent-in-the-loop runner so they all reach connectors the
 * same, safe way. The connector's private auth header stays server-side and is
 * never returned to the caller.
 */
class ConnectorResolver
{
    /**
     * @return array{0:string,1:string,2:array} [endpoint, protocol, headers]
     *
     * @throws ConnectorUnavailableException on non-connector, missing, or
     *         non-public endpoints (carries an HTTP status for the controller).
     */
    public static function resolve(CatalogItem $item): array
    {
        if ($item->type !== 'connector') {
            throw new ConnectorUnavailableException('Only connectors support this action.', 422);
        }

        $meta = $item->metadata ?? [];
        $endpoint = $meta['endpoint'] ?? null;
        $protocol = $meta['protocol'] ?? 'mcp';

        if (! $endpoint) {
            throw new ConnectorUnavailableException('This connector has no endpoint configured.', 422);
        }

        $check = Validator::make(['endpoint' => $endpoint], [
            'endpoint' => ['required', 'url', new PubliclyRoutableUrl],
        ]);
        if ($check->fails()) {
            throw new ConnectorUnavailableException('Connector endpoint is not a valid public URL.', 422);
        }

        $headers = [];
        if (! empty($meta['auth_header'])) {
            $headers['Authorization'] = $meta['auth_header'];
        }

        return [$endpoint, $protocol, $headers];
    }

    /**
     * Resolve and build an MCP client for a connector, rejecting A2A ones.
     */
    public static function mcpClient(CatalogItem $item): McpClient
    {
        [$endpoint, $protocol, $headers] = self::resolve($item);

        if ($protocol === 'a2a') {
            throw new ConnectorUnavailableException('This action supports MCP connectors only.', 422);
        }

        return new McpClient($endpoint, null, $headers);
    }
}
