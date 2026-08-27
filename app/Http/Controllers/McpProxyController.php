<?php

namespace App\Http\Controllers;

use App\Models\McpProxy;
use App\Rules\PubliclyRoutableUrl;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Owner-side management of flight-recorder proxies and their exchanges.
 */
class McpProxyController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            McpProxy::inWorkspaceOf($request->user())
                ->withCount(['exchanges', 'exchanges as flagged_count' => fn ($q) => $q->where('flagged', true)])
                ->orderBy('name')->get()
                ->map(fn ($proxy) => $this->present($proxy))
                ->values()
        );
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if ($user->mcpProxies()->count() >= McpProxy::MAX_PER_USER) {
            return response()->json([
                'message' => 'Recorder limit reached ('.McpProxy::MAX_PER_USER.').',
            ], 422);
        }

        $validated = $this->validated($request, null);

        $proxy = $user->mcpProxies()->create($validated + ['token' => McpProxy::generateToken()]);

        return response()->json($this->present($proxy->loadCount('exchanges')), 201);
    }

    public function update(Request $request, int $id)
    {
        $proxy = McpProxy::inWorkspaceOf($request->user())->findOrFail($id);

        $validated = $this->validated($request, $proxy);

        // An unchanged upstream comes back empty (it is shown, but treat empty
        // as "keep"), and changing it does not wipe history — the recording is
        // the point.
        if (($validated['upstream_url'] ?? '') === '') {
            unset($validated['upstream_url']);
        }

        $proxy->update($validated);

        return response()->json($this->present($proxy->fresh()->loadCount('exchanges')));
    }

    public function destroy(Request $request, int $id)
    {
        McpProxy::inWorkspaceOf($request->user())->findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted']);
    }

    public function exchanges(Request $request, int $id)
    {
        $proxy = McpProxy::inWorkspaceOf($request->user())->findOrFail($id);

        $query = $proxy->exchanges();

        if ($request->boolean('flagged')) {
            $query->where('flagged', true);
        }

        return response()->json([
            'proxy' => $this->present($proxy->loadCount('exchanges')),
            'exchanges' => $query->take(50)->get([
                'id', 'method', 'request', 'response', 'status',
                'duration_ms', 'flagged', 'flag_summary', 'created_at',
            ]),
        ]);
    }

    private function present(McpProxy $proxy): array
    {
        return [
            'id' => $proxy->id,
            'name' => $proxy->name,
            'url' => url('/mcp-proxy/'.$proxy->token),
            'upstream_url' => $proxy->upstream_url,
            'is_enabled' => $proxy->is_enabled,
            'last_used_at' => $proxy->last_used_at,
            'exchanges_count' => $proxy->exchanges_count ?? 0,
            'flagged_count' => $proxy->flagged_count ?? 0,
        ];
    }

    private function validated(Request $request, ?McpProxy $existing): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('mcp_proxies', 'name')
                    ->whereIn('user_id', $request->user()->workspaceUserIds())
                    ->ignore($existing?->id),
            ],
            // We connect to this server-side on every relay: full SSRF vetting.
            'upstream_url' => [
                $existing ? 'nullable' : 'required',
                'string', 'max:2048', 'url', new PubliclyRoutableUrl,
            ],
            'is_enabled' => 'nullable|boolean',
        ]);
    }
}
