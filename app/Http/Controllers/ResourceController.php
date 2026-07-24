<?php

namespace App\Http\Controllers;

use App\Models\CatalogItem;
use Illuminate\Http\Request;

class ResourceController extends Controller
{
    /**
     * Active MCP resources, for the dashboard tester's quick-pick dropdown.
     * Mirrors ToolController/PromptController@active: available to any
     * authenticated user, exposes only what's needed to prefill a
     * resources/read call, and never the connector's stored auth header.
     */
    public function active(Request $request)
    {
        $resources = CatalogItem::ofType('resource')
            ->active()
            ->orderBy('name')
            ->get()
            ->map(fn (CatalogItem $resource) => [
                'id' => $resource->id,
                'name' => $resource->name,
                'description' => $resource->description,
                'provider' => $resource->provider,
                'endpoint' => $resource->metadata['endpoint'] ?? null,
                'protocol' => $resource->metadata['protocol'] ?? 'mcp',
                'uri' => $resource->metadata['uri'] ?? null,
                'mime_type' => $resource->metadata['mimeType'] ?? null,
            ])
            // Need both an endpoint (to call) and a URI (to read).
            ->filter(fn ($r) => $r['endpoint'] !== null && $r['uri'] !== null)
            ->values();

        return response()->json($resources);
    }
}
