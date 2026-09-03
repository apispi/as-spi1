<?php

namespace App\Http\Controllers;

use App\Models\AlertChannel;
use App\Models\Collection;
use App\Models\Environment;
use App\Models\InspectionReport;
use App\Models\McpMock;
use App\Models\McpProxy;
use App\Models\Monitor;
use App\Models\SavedRequest;
use App\Models\StatusPage;
use App\Models\WebhookEndpoint;
use Illuminate\Http\Request;

/**
 * Workspace-wide search powering the command palette (⌘K). Every source is
 * scoped by SharedInWorkspace, so results only ever include the caller's
 * workspace. LIKE queries kept simple and per-type-capped for speed.
 */
class SearchController extends Controller
{
    private const PER_TYPE = 6;

    public function search(Request $request)
    {
        $validated = $request->validate(['q' => 'required|string|max:100']);
        $q = trim($validated['q']);

        if ($q === '') {
            return response()->json(['results' => []]);
        }

        $user = $request->user();
        $results = [];

        $this->collect($results, SavedRequest::inWorkspaceOf($user)
            ->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('url', 'like', "%{$q}%"))
            ->limit(self::PER_TYPE)->get(),
            'saved_request', 'Saved request', '/tester', fn ($r) => $r->url);

        $this->collect($results, Collection::inWorkspaceOf($user)
            ->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('description', 'like', "%{$q}%"))
            ->limit(self::PER_TYPE)->get(),
            'collection', 'Collection', '/collections', fn ($r) => $r->description);

        $this->collect($results, Environment::inWorkspaceOf($user)->where('name', 'like', "%{$q}%")
            ->limit(self::PER_TYPE)->get(), 'environment', 'Environment', '/tester', fn () => null);

        $this->collect($results, Monitor::inWorkspaceOf($user)
            ->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('target_url', 'like', "%{$q}%"))
            ->limit(self::PER_TYPE)->get(), 'monitor', 'Monitor', '/monitors', fn ($r) => $r->last_status);

        $this->collect($results, AlertChannel::inWorkspaceOf($user)->where('name', 'like', "%{$q}%")
            ->limit(self::PER_TYPE)->get(), 'alert_channel', 'Alert channel', '/monitors', fn ($r) => $r->type);

        $this->collect($results, WebhookEndpoint::inWorkspaceOf($user)->where('name', 'like', "%{$q}%")
            ->limit(self::PER_TYPE)->get(), 'webhook', 'Webhook', '/webhooks', fn ($r) => $r->last_status);

        $this->collect($results, McpProxy::inWorkspaceOf($user)
            ->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('upstream_url', 'like', "%{$q}%"))
            ->limit(self::PER_TYPE)->get(), 'recorder', 'Recorder', '/recorder', fn ($r) => $r->upstream_url);

        $this->collect($results, McpMock::inWorkspaceOf($user)->where('name', 'like', "%{$q}%")
            ->limit(self::PER_TYPE)->get(), 'mock', 'Mock server', '/mocks', fn () => null);

        $this->collect($results, StatusPage::inWorkspaceOf($user)->where('name', 'like', "%{$q}%")
            ->limit(self::PER_TYPE)->get(), 'status_page', 'Status page', '/monitors', fn () => null);

        $this->collect($results, InspectionReport::inWorkspaceOf($user)->where('summary', 'like', "%{$q}%")
            ->latest('id')->limit(self::PER_TYPE)->get(), 'report', 'Report', '/reports',
            fn ($r) => $r->type, 'summary');

        return response()->json(['results' => $results]);
    }

    private function collect(array &$results, $rows, string $type, string $typeLabel, string $to, callable $sub, string $labelField = 'name'): void
    {
        foreach ($rows as $row) {
            $results[] = [
                'type' => $type,
                'type_label' => $typeLabel,
                'id' => $row->id,
                'label' => (string) ($row->{$labelField} ?? '—'),
                'sublabel' => $sub($row),
                'to' => $to,
            ];
        }
    }
}
