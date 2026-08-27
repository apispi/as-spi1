<?php

namespace App\Http\Controllers;

use App\Models\Monitor;
use App\Models\StatusPage;
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
            $request->user()->statusPages()
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
        $page = $request->user()->statusPages()->findOrFail($id);

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
        $request->user()->statusPages()->findOrFail($id)->delete();

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

        $monitors = $page->monitors()->get()->map(function (Monitor $monitor) {
            $history = $monitor->results()->take(self::HISTORY)
                ->get(['passed', 'time_ms', 'created_at'])
                ->reverse()->values();

            return [
                'name' => $monitor->name,
                'kind' => $monitor->type === Monitor::TYPE_MCP_DRIFT ? 'mcp_contract' : 'checks',
                'status' => $monitor->last_status,
                'last_run_at' => $monitor->last_run_at,
                'uptime' => $monitor->uptime(),
                'history' => $history->map(fn ($r) => [
                    'ok' => (bool) $r->passed,
                    'time_ms' => $r->time_ms,
                    'at' => $r->created_at,
                ]),
            ];
        });

        $states = $monitors->pluck('status');

        return response()->json([
            'name' => $page->name,
            'description' => $page->description,
            'overall' => $states->contains(Monitor::STATUS_FAILING)
                ? 'failing'
                : ($states->contains(Monitor::STATUS_PASSING) ? 'passing' : 'unknown'),
            'monitors' => $monitors,
            'generated_at' => now(),
        ]);
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
        ];
    }

    /**
     * Only the caller's own monitors can be published.
     */
    private function syncMonitors(StatusPage $page, array $ids, Request $request): void
    {
        $owned = $request->user()->monitors()->whereIn('id', $ids)->pluck('id')->all();

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
                    ->where('user_id', $request->user()->id)
                    ->ignore($existing?->id),
            ],
            'description' => 'nullable|string|max:300',
            'is_enabled' => 'nullable|boolean',
            'monitor_ids' => 'nullable|array|max:20',
            'monitor_ids.*' => 'integer',
        ]);
    }
}
