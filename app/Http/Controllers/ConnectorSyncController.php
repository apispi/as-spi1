<?php

namespace App\Http\Controllers;

use App\Models\CatalogItem;
use App\Rules\PubliclyRoutableUrl;
use App\Services\A2a\A2aClient;
use App\Services\Mcp\McpClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * Populates the catalog by querying a registered connector (an MCP or A2A
 * server) and importing what it exposes as catalog items:
 *   - MCP connectors import tools (tools/list) and prompts (prompts/list)
 *   - A2A connectors import skills (from the agent card)
 *
 * Imports are idempotent: re-syncing upserts by (type, slug) and leaves an
 * item's is_active flag untouched, so activation survives a re-sync.
 */
class ConnectorSyncController extends Controller
{
    public function sync(Request $request, CatalogItem $catalogItem)
    {
        if ($catalogItem->type !== 'connector') {
            return response()->json(['message' => 'Only connectors can be synced.'], 422);
        }

        $meta = $catalogItem->metadata ?? [];
        $endpoint = $meta['endpoint'] ?? null;
        $protocol = $meta['protocol'] ?? 'mcp';

        if (! $endpoint) {
            return response()->json(['message' => 'This connector has no endpoint configured.'], 422);
        }

        // Re-validate the endpoint at sync time: the connector makes an
        // outbound request, and DNS for a stored host may have changed.
        $check = Validator::make(['endpoint' => $endpoint], [
            'endpoint' => ['required', 'url', new PubliclyRoutableUrl],
        ]);
        if ($check->fails()) {
            return response()->json(['message' => 'Connector endpoint is not a valid public URL.'], 422);
        }

        $headers = [];
        if (! empty($meta['auth_header'])) {
            $headers['Authorization'] = $meta['auth_header'];
        }

        try {
            $counts = $protocol === 'a2a'
                ? $this->syncA2a($catalogItem, $endpoint, $headers)
                : $this->syncMcp($catalogItem, $endpoint, $headers);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Sync failed: '.$e->getMessage()], 502);
        }

        $catalogItem->metadata = array_merge($meta, ['last_synced_at' => now()->toIso8601String()]);
        $catalogItem->save();

        return response()->json([
            'message' => 'Synced '.array_sum($counts).' item(s) from '.$catalogItem->name.'.',
            'counts' => $counts,
        ]);
    }

    protected function syncMcp(CatalogItem $connector, string $endpoint, array $headers): array
    {
        $client = new McpClient($endpoint, null, $headers);
        $client->initialize();

        $counts = ['tools' => 0, 'prompts' => 0];

        foreach ($client->listTools()['tools'] ?? [] as $tool) {
            if (empty($tool['name'])) {
                continue;
            }
            $this->import('tool', $connector, $tool['name'], $tool['description'] ?? null, [
                'inputSchema' => $tool['inputSchema'] ?? null,
            ]);
            $counts['tools']++;
        }

        // prompts/list is optional in the MCP spec; ignore servers that lack it.
        try {
            foreach ($client->listPrompts()['prompts'] ?? [] as $prompt) {
                if (empty($prompt['name'])) {
                    continue;
                }
                $this->import('prompt', $connector, $prompt['name'], $prompt['description'] ?? null, [
                    'arguments' => $prompt['arguments'] ?? null,
                ]);
                $counts['prompts']++;
            }
        } catch (Throwable $e) {
            // Server does not support prompts/list — leave the count at zero.
        }

        return $counts;
    }

    protected function syncA2a(CatalogItem $connector, string $endpoint, array $headers): array
    {
        $client = new A2aClient($endpoint, null, $headers);
        $card = $client->getAgentCard();

        $counts = ['skills' => 0];

        foreach ($card['skills'] ?? [] as $skill) {
            $name = $skill['name'] ?? $skill['id'] ?? null;
            if (! $name) {
                continue;
            }
            $this->import('skill', $connector, $name, $skill['description'] ?? null, $skill);
            $counts['skills']++;
        }

        return $counts;
    }

    /**
     * Upsert an imported item, preserving its is_active flag across re-syncs.
     * The slug is namespaced by connector so identically named items from
     * different connectors do not collide.
     */
    protected function import(string $type, CatalogItem $connector, string $name, ?string $description, array $metadata): void
    {
        $slug = $connector->slug.'-'.(Str::slug($name) ?: 'item');

        $item = CatalogItem::firstOrNew(['type' => $type, 'slug' => $slug]);
        $item->fill([
            'name' => $name,
            'description' => $description,
            'provider' => $connector->name,
            'metadata' => $metadata,
        ]);
        $item->save();
    }
}
