<?php

namespace App\Http\Controllers;

use App\Models\CatalogItem;
use Illuminate\Http\Request;

class PromptController extends Controller
{
    /**
     * Active MCP prompts, for the dashboard tester's quick-pick dropdown.
     * Mirrors ToolController@active: available to any authenticated user,
     * exposes only what's needed to prefill a prompts/get call, and never the
     * connector's stored auth header.
     */
    public function active(Request $request)
    {
        $prompts = CatalogItem::ofType('prompt')
            ->active()
            ->orderBy('name')
            ->get()
            ->map(fn (CatalogItem $prompt) => [
                'id' => $prompt->id,
                'name' => $prompt->name,
                'description' => $prompt->description,
                'provider' => $prompt->provider,
                'endpoint' => $prompt->metadata['endpoint'] ?? null,
                'protocol' => $prompt->metadata['protocol'] ?? 'mcp',
                'arguments' => $prompt->metadata['arguments'] ?? null,
            ])
            ->filter(fn ($p) => $p['endpoint'] !== null)
            ->values();

        return response()->json($prompts);
    }
}
