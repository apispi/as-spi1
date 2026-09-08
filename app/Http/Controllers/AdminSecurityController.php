<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Platform-wide security events for admins — every account holder's audited
 * actions (sign-ins, failed sign-ins, key changes) in one place, with a
 * suspicious-activity summary so a burst of failed logins from one IP stands
 * out. Complements the admin-action log and the application log viewer.
 */
class AdminSecurityController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'action' => ['nullable', 'in:'.implode(',', AuditEvent::ACTIONS)],
            'q' => 'nullable|string|max:120',
            'page' => 'nullable|integer|min:1',
        ]);

        $events = AuditEvent::query()
            ->with('user:id,name,email')
            ->when($validated['action'] ?? null, fn ($q, $a) => $q->where('action', $a))
            ->when($validated['q'] ?? null, function ($q, $term) {
                $like = '%'.trim($term).'%';
                $q->where(fn ($w) => $w->where('actor_email', 'like', $like)->orWhere('ip', 'like', $like));
            })
            ->latest('id')
            ->paginate(30)
            ->through(fn (AuditEvent $e) => [
                'id' => $e->id,
                'action' => $e->action,
                'label' => $e->label(),
                'actor' => $e->user ? ['id' => $e->user->id, 'name' => $e->user->name, 'email' => $e->user->email] : null,
                'actor_email' => $e->actor_email,
                'ip' => $e->ip,
                'metadata' => $e->metadata,
                'created_at' => $e->created_at,
            ]);

        return response()->json([
            'events' => $events,
            'summary' => $this->summary(),
        ]);
    }

    private function summary(): array
    {
        $since = now()->subDay();

        $failedIps = AuditEvent::where('action', 'auth.login_failed')
            ->where('created_at', '>=', $since)
            ->whereNotNull('ip')
            ->select('ip', DB::raw('count(*) as attempts'))
            ->groupBy('ip')
            ->orderByDesc('attempts')
            ->limit(5)
            ->get()
            ->map(fn ($r) => ['ip' => $r->ip, 'attempts' => (int) $r->attempts]);

        return [
            'events_24h' => AuditEvent::where('created_at', '>=', $since)->count(),
            'failed_logins_24h' => AuditEvent::where('action', 'auth.login_failed')->where('created_at', '>=', $since)->count(),
            'logins_24h' => AuditEvent::where('action', 'auth.login')->where('created_at', '>=', $since)->count(),
            'distinct_ips_24h' => AuditEvent::where('created_at', '>=', $since)->whereNotNull('ip')->distinct()->count('ip'),
            'top_failed_ips' => $failedIps,
        ];
    }
}
