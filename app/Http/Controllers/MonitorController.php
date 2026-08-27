<?php

namespace App\Http\Controllers;

use App\Models\Monitor;
use App\Services\Monitors\MonitorRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class MonitorController extends Controller
{
    public function index(Request $request)
    {
        $monitors = $request->user()->monitors()
            ->with(['collection:id,name', 'environment:id,name'])
            ->orderBy('name')
            ->get();

        return response()->json($monitors->map(fn (Monitor $m) => $this->present($m)));
    }

    /**
     * One monitor with its recent history, for the uptime/latency view.
     */
    public function show(Request $request, int $id)
    {
        $monitor = $request->user()->monitors()
            ->with(['collection:id,name', 'environment:id,name'])
            ->findOrFail($id);

        $results = $monitor->results()->take(60)->get(
            ['id', 'passed', 'time_ms', 'passed_count', 'total', 'summary', 'inspection_report_id', 'created_at']
        );

        return response()->json($this->present($monitor) + [
            'results' => $results->reverse()->values(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if ($user->monitors()->count() >= Monitor::MAX_PER_USER) {
            return response()->json([
                'message' => 'Monitor limit reached ('.Monitor::MAX_PER_USER.').',
            ], 422);
        }

        $validated = $this->validated($request, null);

        $monitor = $user->monitors()->create(Arr::except($validated, 'alert_channel_ids'));

        $this->syncChannels($monitor, $validated['alert_channel_ids'] ?? null, $request);

        return response()->json($this->present($monitor->fresh()), 201);
    }

    public function update(Request $request, int $id)
    {
        $monitor = $request->user()->monitors()->findOrFail($id);

        $validated = $this->validated($request, $monitor);

        $monitor->update(Arr::except($validated, 'alert_channel_ids'));

        $this->syncChannels($monitor, $validated['alert_channel_ids'] ?? null, $request);

        return response()->json($this->present($monitor->fresh()));
    }

    public function destroy(Request $request, int $id)
    {
        $request->user()->monitors()->findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted']);
    }

    /**
     * Run a monitor now, regardless of its schedule.
     */
    public function run(Request $request, MonitorRunner $runner, int $id)
    {
        $monitor = $request->user()->monitors()->findOrFail($id);

        $result = $runner->run($monitor);

        return response()->json(
            $this->present($monitor->fresh()) + ['result' => $result],
            $result->passed ? 200 : 422
        );
    }

    /**
     * Attach alert channels, ignoring any id that is not the caller's own.
     */
    private function syncChannels(Monitor $monitor, ?array $ids, Request $request): void
    {
        if ($ids === null) {
            return;
        }

        $owned = $request->user()->alertChannels()->whereIn('id', $ids)->pluck('id');

        $monitor->alertChannels()->sync($owned);
    }

    private function present(Monitor $monitor): array
    {
        return [
            'id' => $monitor->id,
            'name' => $monitor->name,
            'type' => $monitor->type,
            'target_url' => $monitor->target_url,
            'collection' => $monitor->collection ? ['id' => $monitor->collection->id, 'name' => $monitor->collection->name] : null,
            'environment' => $monitor->environment ? ['id' => $monitor->environment->id, 'name' => $monitor->environment->name] : null,
            'interval_minutes' => $monitor->interval_minutes,
            'is_enabled' => $monitor->is_enabled,
            'alerts_enabled' => $monitor->alerts_enabled,
            'last_status' => $monitor->last_status,
            'last_run_at' => $monitor->last_run_at,
            'consecutive_failures' => $monitor->consecutive_failures,
            'uptime' => $monitor->uptime(),
            'alert_channel_ids' => $monitor->alertChannels()->pluck('alert_channels.id'),
        ];
    }

    private function validated(Request $request, ?Monitor $existing): array
    {
        $userId = $request->user()->id;

        return $request->validate([
            'name' => [
                'required', 'string', 'max:80',
                Rule::unique('monitors', 'name')->where('user_id', $userId)->ignore($existing?->id),
            ],
            'type' => ['nullable', Rule::in([Monitor::TYPE_COLLECTION, Monitor::TYPE_MCP_DRIFT])],
            // Drift monitors watch a URL instead of running a collection; the
            // target gets the same SSRF vetting as any outbound endpoint.
            'target_url' => [
                'required_if:type,'.Monitor::TYPE_MCP_DRIFT,
                'nullable', 'string', 'max:2048', 'url', new \App\Rules\PubliclyRoutableUrl,
            ],
            // Both must belong to the caller — a monitor cannot be pointed at
            // someone else's collection or environment.
            'collection_id' => [
                'required_unless:type,'.Monitor::TYPE_MCP_DRIFT,
                'nullable', 'integer',
                Rule::exists('collections', 'id')->where('user_id', $userId),
            ],
            'environment_id' => [
                'nullable', 'integer',
                Rule::exists('environments', 'id')->where('user_id', $userId),
            ],
            'interval_minutes' => ['required', 'integer', Rule::in(Monitor::INTERVALS)],
            'is_enabled' => 'nullable|boolean',
            'alerts_enabled' => 'nullable|boolean',
            'alert_channel_ids' => 'nullable|array',
            'alert_channel_ids.*' => 'integer',
        ]);
    }
}
