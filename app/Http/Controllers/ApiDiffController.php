<?php

namespace App\Http\Controllers;

use App\Models\InspectionReport;
use App\Services\Diff\OpenApiDiffer;
use App\Services\Import\ImportException;
use Illuminate\Http\Request;

/**
 * Diff two OpenAPI documents and flag breaking changes. Persisted as a
 * shareable report so a spec review can be linked into a PR, and returned with
 * a 422 when breaking changes are found so a CI job can gate a merge on it.
 */
class ApiDiffController extends Controller
{
    public function openapi(Request $request, OpenApiDiffer $differ)
    {
        $validated = $request->validate([
            'old' => 'required|string|max:2000000',
            'new' => 'required|string|max:2000000',
        ], [
            'old.required' => 'Provide the previous (baseline) OpenAPI document.',
            'new.required' => 'Provide the new OpenAPI document to compare against.',
        ]);

        try {
            $result = $differ->diff($validated['old'], $validated['new']);
        } catch (ImportException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $report = InspectionReport::create([
            'user_id' => $request->user()->id,
            'type' => 'api_diff',
            'summary' => sprintf(
                '%s: %s',
                $result['new_title'],
                $result['breaking']
                    ? $result['breaking_count'].' breaking change'.($result['breaking_count'] === 1 ? '' : 's')
                    : 'no breaking changes'
            ),
            'data' => $result,
        ]);

        // 422 when breaking, so `spi diff … || exit 1` gates a pipeline; the
        // body still carries the full diff either way.
        return response()->json($result + ['report_id' => $report->id], $result['breaking'] ? 422 : 200);
    }
}
