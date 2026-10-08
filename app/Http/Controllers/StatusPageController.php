<?php

namespace App\Http\Controllers;

use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\StatusPageIncident;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StatusPageController extends Controller
{
    /** History points shown per monitor on the public page. */
    private const HISTORY = 60;

    // ------------------------------------------------------------ owner side

    public function index(Request $request)
    {
        return response()->json(
            StatusPage::inWorkspaceOf($request->user())
                ->with('monitors:monitors.id,name')
                ->orderBy('name')->get()
                ->map(fn ($page) => $this->presentForOwner($page))
                ->values()
        );
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if ($user->statusPages()->count() >= StatusPage::MAX_PER_USER) {
            return response()->json([
                'message' => 'Status page limit reached ('.StatusPage::MAX_PER_USER.').',
            ], 422);
        }

        $validated = $this->validated($request, null);

        $page = $user->statusPages()->create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_enabled' => $validated['is_enabled'] ?? true,
            'token' => StatusPage::generateToken(),
        ]);

        $this->syncMonitors($page, $validated['monitor_ids'] ?? [], $request);

        return response()->json($this->presentForOwner($page->fresh()->load('monitors:monitors.id,name')), 201);
    }

    public function update(Request $request, int $id)
    {
        $page = StatusPage::inWorkspaceOf($request->user())->findOrFail($id);

        $validated = $this->validated($request, $page);

        $page->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_enabled' => $validated['is_enabled'] ?? $page->is_enabled,
        ]);

        if (array_key_exists('monitor_ids', $validated)) {
            $this->syncMonitors($page, $validated['monitor_ids'], $request);
        }

        return response()->json($this->presentForOwner($page->fresh()->load('monitors:monitors.id,name')));
    }

    public function destroy(Request $request, int $id)
    {
        StatusPage::inWorkspaceOf($request->user())->findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted']);
    }

    // ------------------------------------------------------------ public side

    /**
     * The public JSON a status page renders from. Token-gated, no auth, and
     * deliberately sparse: names, states, timing — never URLs, steps, or the
     * owner's identity.
     */
    public function show(string $token)
    {
        $page = StatusPage::where('token', $token)->where('is_enabled', true)->first();

        if (! $page) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $pageMonitors = $page->monitors()->get();
        // One grouped query for the whole page instead of two counts each.
        // The history strip below is still one query per monitor: trimming
        // that needs a per-monitor window, which is not worth the raw SQL for
        // a page capped at a handful of monitors.
        $uptimes = Monitor::uptimesFor($pageMonitors);

        $monitors = $pageMonitors->map(function (Monitor $monitor) use ($uptimes) {
            $history = $monitor->results()->take(self::HISTORY)
                ->get(['passed', 'time_ms', 'created_at'])
                ->reverse()->values();

            return [
                'name' => $monitor->name,
                'kind' => match ($monitor->type) {
                    Monitor::TYPE_MCP_DRIFT => 'mcp_contract',
                    Monitor::TYPE_GRAPHQL_DRIFT => 'graphql_schema',
                    Monitor::TYPE_OPENAPI_DRIFT => 'openapi_schema',
                    default => 'checks',
                },
                'status' => $monitor->last_status,
                'last_run_at' => $monitor->last_run_at,
                'uptime' => $uptimes[$monitor->id] ?? null,
                'history' => $history->map(fn ($r) => [
                    'ok' => (bool) $r->passed,
                    'time_ms' => $r->time_ms,
                    'at' => $r->created_at,
                ]),
            ];
        });

        $states = $monitors->pluck('status');

        $incidents = $page->incidents()->published()->get();

        return response()->json([
            'name' => $page->name,
            'description' => $page->description,
            // Monitor-derived, deliberately: the dot keeps meaning "what the
            // checks say". An incident is the owner's commentary alongside it,
            // and conflating the two would make a green page with a posted
            // incident read as a failing one.
            'overall' => $states->contains(Monitor::STATUS_FAILING)
                ? 'failing'
                : ($states->contains(Monitor::STATUS_PASSING) ? 'passing' : 'unknown'),
            'monitors' => $monitors,
            'incidents' => $incidents->map->toPublicArray()->values(),
            'has_open_incident' => $incidents->contains(fn ($i) => ! $i->isResolved()),
            'generated_at' => now(),
        ]);
    }

    // -------------------------------------------------------------- incidents

    public function incidents(Request $request, int $id)
    {
        $page = StatusPage::inWorkspaceOf($request->user())->findOrFail($id);

        return response()->json(
            $page->incidents()->with('user:id,name')->orderByDesc('started_at')
                ->get()->map->toClientArray()->values()
        );
    }

    /**
     * Open an incident. The opening note becomes the first timeline entry, so
     * every incident starts with a statement rather than a bare title.
     */
    public function openIncident(Request $request, int $id)
    {
        $page = StatusPage::inWorkspaceOf($request->user())->findOrFail($id);

        if ($page->incidents()->open()->count() >= StatusPageIncident::MAX_OPEN) {
            return response()->json([
                'message' => 'There are already '.StatusPageIncident::MAX_OPEN.' open incidents on this page. Resolve one first.',
            ], 422);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:160',
            'body' => 'required|string|max:2000',
            'status' => ['nullable', Rule::in(StatusPageIncident::STATUSES)],
            'started_at' => 'nullable|date',
        ]);

        $status = $validated['status'] ?? StatusPageIncident::STATUS_INVESTIGATING;

        $incident = $page->incidents()->create([
            'user_id' => $request->user()->id,
            'title' => $validated['title'],
            'status' => $status,
            // Backdating is allowed: an incident is usually written up once
            // somebody has stopped firefighting, and saying it began then is
            // more honest than saying it began when it got typed.
            'started_at' => $validated['started_at'] ?? now(),
            'resolved_at' => $status === StatusPageIncident::STATUS_RESOLVED ? now() : null,
            'updates' => [[
                'at' => now()->toIso8601String(),
                'status' => $status,
                'body' => $validated['body'],
            ]],
        ]);

        return response()->json($incident->load('user:id,name')->toClientArray(), 201);
    }

    /** Append to the timeline, moving the incident's status with it. */
    public function updateIncident(Request $request, int $id, int $incidentId)
    {
        $incident = $this->findIncident($request, $id, $incidentId);

        $validated = $request->validate([
            'status' => ['required', Rule::in(StatusPageIncident::STATUSES)],
            'body' => 'required|string|max:2000',
        ]);

        $incident->addUpdate($validated['status'], $validated['body']);

        return response()->json($incident->fresh()->load('user:id,name')->toClientArray());
    }

    /** Correct the title. The timeline itself is append-only. */
    public function renameIncident(Request $request, int $id, int $incidentId)
    {
        $incident = $this->findIncident($request, $id, $incidentId);

        $incident->update($request->validate(['title' => 'required|string|max:160']));

        return response()->json($incident->fresh()->load('user:id,name')->toClientArray());
    }

    public function deleteIncident(Request $request, int $id, int $incidentId)
    {
        $this->findIncident($request, $id, $incidentId)->delete();

        return response()->json(['message' => 'Incident removed.']);
    }

    private function findIncident(Request $request, int $id, int $incidentId): StatusPageIncident
    {
        $page = StatusPage::inWorkspaceOf($request->user())->findOrFail($id);

        return $page->incidents()->findOrFail($incidentId);
    }

    // ---------------------------------------------------------------- helpers

    private function presentForOwner(StatusPage $page): array
    {
        return [
            'id' => $page->id,
            'name' => $page->name,
            'description' => $page->description,
            'url' => url('/status/'.$page->token),
            'is_enabled' => $page->is_enabled,
            'monitor_ids' => $page->monitors->pluck('id')->values(),
            'monitors' => $page->monitors->pluck('name')->values(),
            // Surfaced on the owner's list so an incident left open on a public
            // page is visible without opening the page itself.
            'open_incidents' => $page->incidents()->open()->count(),
        ];
    }

    /**
     * Only the caller's own monitors can be published.
     */
    private function syncMonitors(StatusPage $page, array $ids, Request $request): void
    {
        $owned = \App\Models\Monitor::inWorkspaceOf($request->user())->whereIn('id', $ids)->pluck('id')->all();

        $ordered = [];
        foreach (array_values(array_intersect($ids, $owned)) as $position => $id) {
            $ordered[$id] = ['position' => $position];
        }

        $page->monitors()->sync($ordered);
    }

    private function validated(Request $request, ?StatusPage $existing): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:80',
                Rule::unique('status_pages', 'name')
                    ->whereIn('user_id', $request->user()->workspaceUserIds())
                    ->ignore($existing?->id),
            ],
            'description' => 'nullable|string|max:300',
            'is_enabled' => 'nullable|boolean',
            'monitor_ids' => 'nullable|array|max:20',
            'monitor_ids.*' => 'integer',
        ]);
    }
}
