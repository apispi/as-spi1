<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Lets a user see and revoke their own active sessions ("where you're signed
 * in"), so a lost or shared device can be signed out remotely. Pairs with 2FA
 * as account-security tooling.
 *
 * Sessions are referenced by a one-way handle (a hash of the session id), never
 * the raw id — the id is a bearer credential and must not be handed to the
 * browser for other devices.
 */
class SessionController extends Controller
{
    public function index(Request $request)
    {
        $currentId = $request->session()->getId();

        $sessions = DB::table('sessions')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity']);

        return response()->json($sessions->map(fn ($s) => [
            'handle' => $this->handle($s->id),
            'ip' => $s->ip_address,
            'device' => $this->describeAgent($s->user_agent),
            'last_active' => $s->last_activity ? now()->createFromTimestamp($s->last_activity)->toIso8601String() : null,
            'is_current' => hash_equals($s->id, $currentId),
        ])->values());
    }

    /**
     * Revoke one other session by its handle. The current session cannot be
     * revoked here — signing yourself out is what Logout is for.
     */
    public function revoke(Request $request, string $handle)
    {
        $currentId = $request->session()->getId();

        $row = DB::table('sessions')
            ->where('user_id', $request->user()->id)
            ->get(['id'])
            ->first(fn ($s) => hash_equals($this->handle($s->id), $handle));

        if (! $row) {
            return response()->json(['message' => 'Session not found.'], 404);
        }
        if (hash_equals($row->id, $currentId)) {
            return response()->json(['message' => 'That is your current session — use Sign out instead.'], 422);
        }

        DB::table('sessions')->where('id', $row->id)->delete();
        AuditEvent::record('session.revoked', $request->user(), $request);

        return response()->json(['message' => 'Device signed out.']);
    }

    /** Sign out every OTHER session, keeping the current one. */
    public function revokeOthers(Request $request)
    {
        $currentId = $request->session()->getId();

        $count = DB::table('sessions')
            ->where('user_id', $request->user()->id)
            ->where('id', '!=', $currentId)
            ->delete();

        AuditEvent::record('session.revoked_others', $request->user(), $request, ['count' => $count]);

        return response()->json(['message' => 'Signed out other devices.', 'count' => $count]);
    }

    private function handle(string $id): string
    {
        return substr(hash('sha256', $id), 0, 24);
    }

    /**
     * A short, human label for a user agent — browser + OS, best-effort. Not a
     * full UA parser; just enough to recognise a device in the list.
     */
    private function describeAgent(?string $ua): string
    {
        if (! $ua) {
            return 'Unknown device';
        }

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Browser',
        };

        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Unknown OS',
        };

        return "{$browser} on {$os}";
    }
}
