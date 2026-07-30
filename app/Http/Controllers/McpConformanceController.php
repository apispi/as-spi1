<?php

namespace App\Http\Controllers;

use App\Models\CatalogItem;
use App\Services\Catalog\ConnectorResolver;
use App\Services\Catalog\ConnectorUnavailableException;
use App\Services\Mcp\McpConformanceGrader;
use Throwable;

/**
 * Grades how faithfully a synced MCP connector implements the protocol spec.
 * Admin-only, since it exercises a connector's private endpoint. The resulting
 * grade is persisted onto the connector's metadata so the Catalog can surface
 * it alongside the reachability health badge.
 */
class McpConformanceController extends Controller
{
    public function grade(CatalogItem $catalogItem)
    {
        try {
            $client = ConnectorResolver::mcpClient($catalogItem);
        } catch (ConnectorUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        try {
            $report = (new McpConformanceGrader($client))->run();
        } catch (Throwable $e) {
            return response()->json(['message' => 'Conformance run failed: '.$e->getMessage()], 502);
        }

        $catalogItem->metadata = array_merge($catalogItem->metadata ?? [], [
            'conformance_grade' => $report['grade'],
            'conformance_score' => $report['score'],
            'conformance_checked_at' => now()->toIso8601String(),
        ]);
        $catalogItem->save();

        return response()->json($report);
    }
}
