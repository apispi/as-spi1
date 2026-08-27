<?php

namespace App\Http\Controllers;

use App\Models\AlertChannel;
use App\Models\Monitor;
use App\Models\MonitorResult;
use App\Rules\PubliclyRoutableUrl;
use App\Services\Alerts\AlertDispatcher;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AlertChannelController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            AlertChannel::inWorkspaceOf($request->user())->with('owner:id,name')->orderBy('name')->get()->map->toClientArray()->values()
        );
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if ($user->alertChannels()->count() >= AlertChannel::MAX_PER_USER) {
            return response()->json([
                'message' => 'Alert channel limit reached ('.AlertChannel::MAX_PER_USER.').',
            ], 422);
        }

        $channel = $user->alertChannels()->create($this->validated($request, null));

        return response()->json($channel->toClientArray(), 201);
    }

    public function update(Request $request, int $id)
    {
        $channel = AlertChannel::inWorkspaceOf($request->user())->findOrFail($id);

        $validated = $this->validated($request, $channel);

        // An unchanged URL comes back empty, because it is never sent to the
        // browser in full — keep the stored one rather than wiping it.
        if (($validated['url'] ?? '') === '') {
            unset($validated['url']);
        }

        $channel->update($validated);

        return response()->json($channel->fresh()->toClientArray());
    }

    public function destroy(Request $request, int $id)
    {
        AlertChannel::inWorkspaceOf($request->user())->findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted']);
    }

    /**
     * Send a sample alert, so a channel can be proven before an incident
     * depends on it.
     */
    public function test(Request $request, AlertDispatcher $dispatcher, int $id)
    {
        $channel = AlertChannel::inWorkspaceOf($request->user())->findOrFail($id);

        $monitor = new Monitor(['name' => 'Test alert']);
        $monitor->id = 0;

        $result = new MonitorResult([
            'passed' => false,
            'time_ms' => 0,
            'passed_count' => 0,
            'total' => 1,
            'summary' => 'This is a test alert from Spi.',
        ]);

        $delivered = $dispatcher->send(
            $channel,
            $dispatcher->payload($channel, $monitor, $result, Monitor::STATUS_FAILING)
        );

        return response()->json(
            $channel->fresh()->toClientArray() + ['delivered' => $delivered],
            $delivered ? 200 : 422
        );
    }

    private function validated(Request $request, ?AlertChannel $existing): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('alert_channels', 'name')
                    ->whereIn('user_id', $request->user()->workspaceUserIds())
                    ->ignore($existing?->id),
            ],
            'type' => ['required', Rule::in(AlertChannel::TYPES)],
            // We POST to this from the server, so it gets the same SSRF
            // treatment as any other outbound target.
            'url' => [
                $existing ? 'nullable' : 'required',
                'string', 'max:2048', 'url', new PubliclyRoutableUrl,
            ],
            'is_enabled' => 'nullable|boolean',
        ]);
    }
}
