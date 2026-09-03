<?php

namespace App\Http\Controllers;

use App\Models\InspectionReport;
use App\Models\SavedRequest;
use App\Services\Snapshots\SnapshotDiffer;
use Illuminate\Http\Request;

/**
 * Capture and check response snapshots — a "golden" response a saved request is
 * expected to keep returning, checked value-by-value on later runs.
 *
 * Mirrors the contract endpoints: the client posts the actual response body it
 * already fetched (via the proxy), so there is no second outbound call here.
 */
class SnapshotController extends Controller
{
    public function __construct(
        private readonly SnapshotDiffer $differ,
    ) {
    }

    /** Metadata about the stored snapshot (never the full body). */
    public function show(Request $request, int $id)
    {
        $saved = SavedRequest::inWorkspaceOf($request->user())->findOrFail($id);

        return response()->json($this->meta($saved));
    }

    /**
     * Capture the current response as the golden snapshot, or clear it by
     * posting an empty body.
     */
    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'status' => 'nullable|integer',
            'body' => 'nullable|string|max:1000000',
        ]);

        $saved = SavedRequest::inWorkspaceOf($request->user())->findOrFail($id);

        if (($validated['body'] ?? '') === '' && ($validated['status'] ?? null) === null) {
            $saved->update(['snapshot' => null, 'snapshot_taken_at' => null]);

            return response()->json($this->meta($saved->refresh()));
        }

        $saved->update([
            'snapshot' => [
                'status' => $validated['status'] ?? null,
                'body' => $validated['body'] ?? '',
            ],
            'snapshot_taken_at' => now(),
        ]);

        return response()->json($this->meta($saved->refresh()));
    }

    /**
     * Diff the posted response against the stored snapshot and persist a report.
     */
    public function check(Request $request, int $id)
    {
        $validated = $request->validate([
            'status' => 'nullable|integer',
            'body' => 'nullable|string|max:1000000',
        ]);

        $saved = SavedRequest::inWorkspaceOf($request->user())->findOrFail($id);

        if (empty($saved->snapshot)) {
            return response()->json(['message' => 'This request has no snapshot yet.'], 422);
        }

        $diff = $this->differ->compare($saved->snapshot, [
            'status' => $validated['status'] ?? null,
            'body' => $validated['body'] ?? '',
        ]);

        $report = InspectionReport::create([
            'user_id' => $request->user()->id,
            'type' => 'snapshot',
            'summary' => sprintf(
                '%s — %s',
                $saved->name ?: 'Request',
                $diff['matches']
                    ? 'matches snapshot'
                    : $this->diffSummary($diff)
            ),
            'data' => $diff + ['request' => ['id' => $saved->id, 'name' => $saved->name]],
        ]);

        return response()->json($diff + ['report_id' => $report->id], $diff['matches'] ? 200 : 422);
    }

    private function diffSummary(array $diff): string
    {
        $parts = [];
        if ($diff['status_changed']) {
            $parts[] = 'status '.$diff['status_from'].'→'.$diff['status_to'];
        }
        if ($diff['changed_count']) {
            $parts[] = $diff['changed_count'].' changed';
        }
        if ($diff['added_count']) {
            $parts[] = $diff['added_count'].' added';
        }
        if ($diff['removed_count']) {
            $parts[] = $diff['removed_count'].' removed';
        }

        return $parts === [] ? 'differs' : implode(', ', $parts);
    }

    private function meta(SavedRequest $saved): array
    {
        return [
            'has_snapshot' => ! empty($saved->snapshot),
            'taken_at' => $saved->snapshot_taken_at,
            'status' => $saved->snapshot['status'] ?? null,
        ];
    }

}
