<?php

namespace App\Http\Controllers;

use App\Models\CatalogItem;
use Illuminate\Http\Request;

class ToolController extends Controller
{
    /**
     * Active MCP tools, for the dashboard tester's quick-pick dropdown.
     *
     * Available to any authenticated user since active items are workspace-
     * wide. Deliberately exposes only what is needed to prefill a call
     * (endpoint, protocol, input schema) and never the connector's stored
     * auth header — callers supply their own credentials in the tester.
     */
    public function active(Request $request)
    {
        $tools = CatalogItem::ofType('tool')
            ->active()
            ->orderBy('name')
            ->get()
            ->map(fn (CatalogItem $tool) => [
                'id' => $tool->id,
                'name' => $tool->name,
                'description' => $tool->description,
                'provider' => $tool->provider,
                'endpoint' => $tool->metadata['endpoint'] ?? null,
                'protocol' => $tool->metadata['protocol'] ?? 'mcp',
                'input_schema' => $tool->metadata['inputSchema'] ?? null,
            ])
            // Only tools we can actually address are useful in the tester.
            ->filter(fn ($t) => $t['endpoint'] !== null)
            ->values();

        return response()->json($tools);
    }
}
