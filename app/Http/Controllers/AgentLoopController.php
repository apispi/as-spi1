<?php

namespace App\Http\Controllers;

use App\Models\CatalogItem;
use App\Models\InspectionReport;
use App\Services\Agent\AgentLoopRunner;
use App\Services\Catalog\ConnectorResolver;
use App\Services\Catalog\ConnectorUnavailableException;
use App\Services\Scx\ScxClient;
use App\Services\Scx\ScxKeyMissingException;
use Illuminate\Http\Request;
use Throwable;

/**
 * Runs an agent-in-the-loop session against an MCP connector: the SCX model
 * pursues a goal by calling the connector's tools, and the full trace is
 * returned. Admin-only, since it exercises a connector's private endpoint and
 * may invoke tools with side effects.
 */
class AgentLoopController extends Controller
{
    public function run(Request $request, CatalogItem $catalogItem)
    {
        $validated = $request->validate([
            'goal' => 'required|string|max:2000',
            'max_steps' => 'nullable|integer|min:1|max:12',
        ]);

        try {
            $scx = ScxClient::forUser($request->user());
        } catch (ScxKeyMissingException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }

        try {
            $mcp = ConnectorResolver::mcpClient($catalogItem);
        } catch (ConnectorUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        $runner = new AgentLoopRunner($mcp, $scx, $validated['max_steps'] ?? 8);

        try {
            $trace = $runner->run($validated['goal']);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Agent run failed: '.$e->getMessage()], 502);
        }

        $report = InspectionReport::record($request->user()->id, $catalogItem, 'agent_loop', $trace);

        return response()->json($trace + ['report_id' => $report->id]);
    }
}
