<?php

namespace App\Http\Controllers;

use App\Models\WebhookEndpoint;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Owner-side management of capture endpoints.
 */
class WebhookEndpointController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            WebhookEndpoint::inWorkspaceOf($request->user())
                ->withCount('captures')
                ->orderBy('name')->get()
                ->map(fn ($e) => $this->present($e))
                ->values()
        );
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if ($user->webhookEndpoints()->count() >= WebhookEndpoint::MAX_PER_USER) {
            return response()->json([
                'message' => 'Webhook endpoint limit reached ('.WebhookEndpoint::MAX_PER_USER.').',
            ], 422);
        }

        $validated = $this->validated($request, null);

        $endpoint = $user->webhookEndpoints()->create($validated + [
            'token' => WebhookEndpoint::generateToken(),
        ]);

        return response()->json($this->present($endpoint->loadCount('captures')), 201);
    }

    public function update(Request $request, int $id)
    {
        $endpoint = WebhookEndpoint::inWorkspaceOf($request->user())->findOrFail($id);

        $endpoint->update($this->validated($request, $endpoint));

        // Clearing or changing the expectation resets the silence state; the
        // next check re-evaluates from a clean slate.
        if ($endpoint->wasChanged('expect_interval_minutes')) {
            $endpoint->forceFill([
                'last_status' => $endpoint->last_received_at
                    ? WebhookEndpoint::STATUS_RECEIVING
                    : WebhookEndpoint::STATUS_UNKNOWN,
            ])->save();
        }

        return response()->json($this->present($endpoint->fresh()->loadCount('captures')));
    }

    public function destroy(Request $request, int $id)
    {
        WebhookEndpoint::inWorkspaceOf($request->user())->findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted']);
    }

    public function captures(Request $request, int $id)
    {
        $endpoint = WebhookEndpoint::inWorkspaceOf($request->user())->findOrFail($id);

        return response()->json([
            'endpoint' => $this->present($endpoint->loadCount('captures')),
            'captures' => $endpoint->captures()->take(50)
                ->get(['id', 'method', 'headers', 'query', 'body', 'ip', 'created_at']),
        ]);
    }

    private function present(WebhookEndpoint $endpoint): array
    {
        return [
            'id' => $endpoint->id,
            'name' => $endpoint->name,
            'url' => url('/hook/'.$endpoint->token),
            'expect_interval_minutes' => $endpoint->expect_interval_minutes,
            'alerts_enabled' => $endpoint->alerts_enabled,
            'last_received_at' => $endpoint->last_received_at,
            'last_status' => $endpoint->last_status,
            'captures_count' => $endpoint->captures_count ?? 0,
        ];
    }

    private function validated(Request $request, ?WebhookEndpoint $existing): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('webhook_endpoints', 'name')
                    ->whereIn('user_id', $request->user()->workspaceUserIds())
                    ->ignore($existing?->id),
            ],
            'expect_interval_minutes' => 'nullable|integer|min:5|max:10080',
            'alerts_enabled' => 'nullable|boolean',
        ]);
    }
}
