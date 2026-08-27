<?php

namespace App\Http\Controllers;

use App\Models\InspectionReport;
use Illuminate\Http\Request;

/**
 * Saved connector-inspection reports: listing, viewing, comparing two runs,
 * deleting, and toggling a public share link. All authenticated actions are
 * scoped to the owner; the public share view lives on its own unauthenticated
 * route (see routes/web.php) and is keyed by an unguessable token.
 */
class ReportController extends Controller
{
    /**
     * List the current user's reports, newest first, optionally filtered by
     * type or connector. Bodies are omitted here — the list only needs the
     * summary; full data is fetched per report.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'type' => 'nullable|in:agent_loop,conformance,security',
            'connector_slug' => 'nullable|string',
        ]);

        $reports = InspectionReport::inWorkspaceOf($request->user())
            ->when($validated['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($validated['connector_slug'] ?? null, fn ($q, $s) => $q->where('connector_slug', $s))
            ->orderByDesc('id')
            ->get(['id', 'type', 'summary', 'connector_slug', 'connector_name', 'share_token', 'created_at'])
            ->map(fn ($r) => $this->listRow($r));

        return response()->json(['reports' => $reports]);
    }

    public function show(Request $request, InspectionReport $report)
    {
        $this->authorizeOwner($request, $report);

        return response()->json($this->full($report));
    }

    public function destroy(Request $request, InspectionReport $report)
    {
        $this->authorizeOwner($request, $report);
        $report->delete();

        return response()->json(['message' => 'Report deleted.']);
    }

    /**
     * Compare two of the user's reports of the same type — the frontend renders
     * a side-by-side diff. Rejects mismatched types, which aren't comparable.
     */
    public function compare(Request $request)
    {
        $validated = $request->validate([
            'a' => 'required|integer',
            'b' => 'required|integer',
        ]);

        $a = InspectionReport::inWorkspaceOf($request->user())->findOrFail($validated['a']);
        $b = InspectionReport::inWorkspaceOf($request->user())->findOrFail($validated['b']);

        if ($a->type !== $b->type) {
            return response()->json(['message' => 'Reports must be the same type to compare.'], 422);
        }

        return response()->json([
            'type' => $a->type,
            'a' => $this->full($a),
            'b' => $this->full($b),
        ]);
    }

    public function share(Request $request, InspectionReport $report)
    {
        $this->authorizeOwner($request, $report);
        $token = $report->share();

        return response()->json([
            'shared' => true,
            'share_token' => $token,
            'url' => url('/r/'.$token),
        ]);
    }

    public function revokeShare(Request $request, InspectionReport $report)
    {
        $this->authorizeOwner($request, $report);
        $report->revokeShare();

        return response()->json(['shared' => false]);
    }

    /**
     * Public, unauthenticated read of a shared report by its token.
     */
    public function showShared(string $token)
    {
        $report = InspectionReport::where('share_token', $token)->firstOrFail();

        return response()->json($this->full($report) + ['shared' => true]);
    }

    private function authorizeOwner(Request $request, InspectionReport $report): void
    {
        // Reports are shared across the workspace, not just visible to their
        // creator.
        abort_unless(
            in_array($report->user_id, $request->user()->workspaceUserIds(), true),
            403
        );
    }

    private function listRow(InspectionReport $r): array
    {
        return [
            'id' => $r->id,
            'type' => $r->type,
            'summary' => $r->summary,
            'connector_slug' => $r->connector_slug,
            'connector_name' => $r->connector_name,
            'is_shared' => ! empty($r->share_token),
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }

    private function full(InspectionReport $r): array
    {
        return [
            'id' => $r->id,
            'type' => $r->type,
            'summary' => $r->summary,
            'connector_slug' => $r->connector_slug,
            'connector_name' => $r->connector_name,
            'is_shared' => ! empty($r->share_token),
            'data' => $r->data,
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
