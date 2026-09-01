<?php

namespace App\Http\Controllers;

use App\Models\McpMock;
use App\Models\McpProxy;
use App\Services\Mcp\McpTrafficSynthesizer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Owner-side management of mock MCP servers.
 */
class McpMockController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            McpMock::inWorkspaceOf($request->user())
                ->with('owner:id,name')->orderBy('name')->get()
                ->map(fn ($m) => $this->present($m))->values()
        );
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if ($user->mcpMocks()->count() >= McpMock::MAX_PER_USER) {
            return response()->json(['message' => 'Mock limit reached ('.McpMock::MAX_PER_USER.').'], 422);
        }

        $mock = $user->mcpMocks()->create($this->validated($request, null) + ['token' => McpMock::generateToken()]);

        return response()->json($this->present($mock, full: true), 201);
    }

    public function show(Request $request, int $id)
    {
        $mock = McpMock::inWorkspaceOf($request->user())->findOrFail($id);

        return response()->json($this->present($mock, full: true));
    }

    public function update(Request $request, int $id)
    {
        $mock = McpMock::inWorkspaceOf($request->user())->findOrFail($id);
        $mock->update($this->validated($request, $mock));

        return response()->json($this->present($mock->fresh(), full: true));
    }

    public function destroy(Request $request, int $id)
    {
        McpMock::inWorkspaceOf($request->user())->findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted']);
    }

    /**
     * Build a mock from a flight recorder's captured traffic: each tool the
     * recorder saw becomes a mock tool with its observed input schema and a
     * real sample response to replay. The loop closes — record, then serve it
     * back as a stand-in.
     */
    public function fromRecorder(Request $request, McpTrafficSynthesizer $synthesizer, int $proxyId)
    {
        $user = $request->user();

        if ($user->mcpMocks()->count() >= McpMock::MAX_PER_USER) {
            return response()->json(['message' => 'Mock limit reached ('.McpMock::MAX_PER_USER.').'], 422);
        }

        $proxy = McpProxy::inWorkspaceOf($user)->findOrFail($proxyId);
        $synth = $synthesizer->synthesize($proxy);

        $tools = collect($synth['tools'])
            ->take(McpMock::MAX_TOOLS)
            ->map(fn ($t) => [
                'name' => $t['name'],
                'description' => $t['description'],
                'inputSchema' => $t['observed_input_schema'] ?? $t['declared_input_schema']
                    ?? ['type' => 'object', 'properties' => (object) []],
                'response' => $t['sample_output'],
            ])->values()->all();

        $mock = $user->mcpMocks()->create([
            'name' => $this->uniqueName($user, $proxy->name.' mock'),
            'token' => McpMock::generateToken(),
            'server_name' => $proxy->name.' (mock)',
            'tools' => $tools,
        ]);

        return response()->json($this->present($mock, full: true) + [
            'seeded_from' => $proxy->name,
            'tool_count' => count($tools),
        ], 201);
    }

    private function present(McpMock $mock, bool $full = false): array
    {
        $base = [
            'id' => $mock->id,
            'name' => $mock->name,
            'url' => url('/mcp-mock/'.$mock->token),
            'server_name' => $mock->server_name,
            'server_version' => $mock->server_version,
            'is_enabled' => $mock->is_enabled,
            'tool_count' => count($mock->tools ?? []),
            'owner' => $mock->relationLoaded('owner') && $mock->owner
                ? ['id' => $mock->owner->id, 'name' => $mock->owner->name] : null,
        ];

        return $full ? $base + ['tools' => $mock->tools ?? []] : $base;
    }

    private function uniqueName($user, string $name): string
    {
        $taken = McpMock::inWorkspaceOf($user)->pluck('name')->all();
        $candidate = $name;
        $n = 2;
        while (in_array($candidate, $taken, true)) {
            $candidate = $name.' '.$n++;
        }

        return $candidate;
    }

    private function validated(Request $request, ?McpMock $existing): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('mcp_mocks', 'name')
                    ->whereIn('user_id', $request->user()->workspaceUserIds())
                    ->ignore($existing?->id),
            ],
            'server_name' => 'nullable|string|max:80',
            'server_version' => 'nullable|string|max:40',
            'is_enabled' => 'nullable|boolean',
            'tools' => 'nullable|array|max:'.McpMock::MAX_TOOLS,
            'tools.*.name' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.\/-]+$/'],
            'tools.*.description' => 'nullable|string|max:2000',
            'tools.*.inputSchema' => 'nullable|array',
            'tools.*.response' => 'nullable|array',
        ]);
    }
}
