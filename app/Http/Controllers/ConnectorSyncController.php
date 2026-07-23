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
    /** Items imported during the current sync run (collected by import()). */
    protected array $imported = [];

    public function sync(Request $request, CatalogItem $catalogItem)
    {
        [$endpoint, $protocol, $headers, $error] = $this->resolveConnector($catalogItem);
        if ($error) {
            return $error;
        }

        $activate = $request->boolean('activate');
        $this->imported = [];

        try {
            $counts = $protocol === 'a2a'
                ? $this->syncA2a($catalogItem, $endpoint, $headers)
                : $this->syncMcp($catalogItem, $endpoint, $headers);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Sync failed: '.$e->getMessage()], 502);
        }

        // Optionally activate everything this connector just contributed, in
        // one go, so an admin doesn't have to toggle each item.
        $activatedCount = 0;
        if ($activate && $this->imported !== []) {
            $activatedCount = CatalogItem::whereIn('id', $this->imported)
                ->where('is_active', false)
                ->update(['is_active' => true]);
        }

        $catalogItem->metadata = array_merge($catalogItem->metadata ?? [], [
            'last_synced_at' => now()->toIso8601String(),
        ]);
        $catalogItem->save();

        $message = 'Synced '.array_sum($counts).' item(s) from '.$catalogItem->name.'.';
        if ($activate) {
            $message .= ' Activated '.$activatedCount.'.';
        }

        return response()->json([
            'message' => $message,
            'counts' => $counts,
            'activated' => $activatedCount,
        ]);
    }

    /**
     * Lightweight reachability probe: handshake with the connector (MCP
     * initialize / A2A agent-card) and report whether it responded, with
     * latency. Unreachable is a normal answer (200 with reachable=false), not
     * a server error — the UI shows it as a status badge.
     */
    public function check(Request $request, CatalogItem $catalogItem)
    {
        [$endpoint, $protocol, $headers, $error] = $this->resolveConnector($catalogItem);
        if ($error) {
            return $error;
        }

        $start = microtime(true);
        $reachable = true;
        $message = 'Connector is reachable.';
        $info = null;

        try {
            if ($protocol === 'a2a') {
                $card = (new A2aClient($endpoint, null, $headers))->getAgentCard();
                $info = $card['name'] ?? null;
            } else {
                $init = (new McpClient($endpoint, null, $headers))->initialize();
                $info = trim(($init['serverInfo']['name'] ?? '').' '.($init['serverInfo']['version'] ?? '')) ?: null;
            }
        } catch (Throwable $e) {
            $reachable = false;
            $message = $e->getMessage();
        }

        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        $catalogItem->metadata = array_merge($catalogItem->metadata ?? [], [
            'last_checked_at' => now()->toIso8601String(),
            'last_check_ok' => $reachable,
        ]);
        $catalogItem->save();

        return response()->json([
            'reachable' => $reachable,
            'latency_ms' => $latencyMs,
            'info' => $info,
            'message' => $message,
        ]);
    }

    /**
     * Shared guard: ensure the item is a connector with a valid, public
     * endpoint. Returns [endpoint, protocol, headers, null] on success or
     * [null, null, null, JsonResponse] on failure.
     */
    protected function resolveConnector(CatalogItem $item): array
    {
        if ($item->type !== 'connector') {
            return [null, null, null, response()->json(['message' => 'Only connectors support this action.'], 422)];
        }

        $meta = $item->metadata ?? [];
        $endpoint = $meta['endpoint'] ?? null;
        $protocol = $meta['protocol'] ?? 'mcp';

        if (! $endpoint) {
            return [null, null, null, response()->json(['message' => 'This connector has no endpoint configured.'], 422)];
        }

        // Re-validate at call time: the connector makes an outbound request and
        // DNS for a stored host may have changed.
        $check = Validator::make(['endpoint' => $endpoint], [
            'endpoint' => ['required', 'url', new PubliclyRoutableUrl],
        ]);
        if ($check->fails()) {
            return [null, null, null, response()->json(['message' => 'Connector endpoint is not a valid public URL.'], 422)];
        }

        $headers = [];
        if (! empty($meta['auth_header'])) {
            $headers['Authorization'] = $meta['auth_header'];
        }

        return [$endpoint, $protocol, $headers, null];
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
        $connectorMeta = $connector->metadata ?? [];

        $item = CatalogItem::firstOrNew(['type' => $type, 'slug' => $slug]);
        $item->fill([
            'name' => $name,
            'description' => $description,
            'provider' => $connector->name,
            // Store how to reach the item's connector so the tester can call
            // it, but never the connector's auth header — that stays with the
            // admin-only connector record.
            'metadata' => array_merge($metadata, [
                'connector_slug' => $connector->slug,
                'endpoint' => $connectorMeta['endpoint'] ?? null,
                'protocol' => $connectorMeta['protocol'] ?? 'mcp',
            ]),
        ]);
        $item->save();

        $this->imported[] = $item->id;
    }
}
