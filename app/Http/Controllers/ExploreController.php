<?php

namespace App\Http\Controllers;

use App\Models\InspectionReport;
use App\Rules\PubliclyRoutableUrl;
use App\Services\Agent\ServerExplorer;
use App\Services\Mcp\McpClient;
use App\Services\Scx\ScxClient;
use App\Services\Scx\ScxKeyMissingException;
use Illuminate\Http\Request;
use Throwable;

/**
 * Autonomous exploration of an arbitrary MCP server. Unlike the admin-only
 * connector agent loop, this points at any public MCP URL and is safe by
 * default (destructive tools are refused). Runs on the caller's SCX key.
 */
class ExploreController extends Controller
{
    public function explore(Request $request)
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'url', new PubliclyRoutableUrl],
            'goal' => 'required|string|max:2000',
            'headers' => 'nullable|array',
            'max_steps' => 'nullable|integer|min:1|max:12',
            'safe_mode' => 'nullable|boolean',
        ]);

        try {
            $scx = ScxClient::forUser($request->user());
        } catch (ScxKeyMissingException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }

        $headers = collect($validated['headers'] ?? [])
            ->filter(fn ($v, $k) => ! in_array(strtolower((string) $k), ['host', 'content-length']))
            ->all();

        $explorer = new ServerExplorer(
            new McpClient($validated['url'], null, $headers),
            $scx,
            $validated['max_steps'] ?? 8,
            // Default ON: pointing an autonomous model at a live server must be
            // safe unless the caller explicitly opts out.
            $validated['safe_mode'] ?? true,
        );

        try {
            $result = $explorer->explore($validated['goal']);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Exploration failed: '.$e->getMessage()], 502);
        }

        $report = InspectionReport::create([
            'user_id' => $request->user()->id,
            'type' => 'exploration',
            'summary' => sprintf(
                '%s — %s (%d tool call%s%s)',
                mb_substr($validated['goal'], 0, 60),
                $result['completed'] ? 'goal met' : 'incomplete',
                $result['tool_call_count'],
                $result['tool_call_count'] === 1 ? '' : 's',
                $result['blocked_attempts'] ? ', '.count($result['blocked_attempts']).' blocked' : ''
            ),
            'data' => $result,
        ]);

        return response()->json($result + ['report_id' => $report->id]);
    }
}
