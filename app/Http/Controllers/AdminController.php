<?php

namespace App\Http\Controllers;

use App\Models\AdminAction;
use App\Models\RequestHistory;
use App\Models\SavedRequest;
use App\Models\User;
use App\Models\InspectionReport;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    /**
     * List users, paginated, with optional search over name/email.
     */
    public function users(Request $request)
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:255',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            // active (default) | trashed | all
            'filter' => 'nullable|string|in:active,trashed,all',
        ]);

        $query = User::with('organisation:id,name')
            ->withCount('savedRequests')
            ->when(($validated['filter'] ?? 'active') === 'trashed', fn ($q) => $q->onlyTrashed())
            ->when(($validated['filter'] ?? 'active') === 'all', fn ($q) => $q->withTrashed())
            ->orderBy('created_at', 'desc');

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $paginated = $query->paginate($validated['per_page'] ?? 25);

        $paginated->getCollection()->transform(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
                'email_verified' => $user->email_verified_at !== null,
                'deleted_at' => $user->deleted_at?->toDateTimeString(),
                'saved_requests_count' => $user->saved_requests_count,
                'organisation' => $user->organisation
                    ? ['id' => $user->organisation->id, 'name' => $user->organisation->name]
                    : null,
                'created_at' => $user->created_at->toDateTimeString(),
                'updated_at' => $user->updated_at->toDateTimeString(),
            ];
        });

        return response()->json($paginated);
    }

    /**
     * Get dashboard stats.
     */
    public function stats(Request $request)
    {
        $protocolBreakdown = RequestHistory::select('protocol', DB::raw('count(*) as count'))
            ->groupBy('protocol')
            ->pluck('count', 'protocol');

        return response()->json([
            'total_users' => User::count(),
            'admin_users' => User::where('is_admin', true)->count(),
            'total_saved_requests' => SavedRequest::count(),
            'new_users_this_week' => User::where('created_at', '>=', now()->subWeek())->count(),
            'total_requests' => RequestHistory::count(),
            'requests_this_week' => RequestHistory::where('created_at', '>=', now()->subWeek())->count(),
            'protocol_breakdown' => [
                'rest' => (int) ($protocolBreakdown['rest'] ?? 0),
                'mcp' => (int) ($protocolBreakdown['mcp'] ?? 0),
                'a2a' => (int) ($protocolBreakdown['a2a'] ?? 0),
            ],
        ]);
    }

    /**
     * List recent admin actions (audit log).
     */
    public function actions(Request $request)
    {
        $actions = AdminAction::with('admin:id,name,email')
            ->orderBy('created_at', 'desc')
            ->paginate(25);

        return response()->json($actions);
    }

    /**
     * Toggle admin status for a user.
     */
    /**
     * Every monitor in the workspace, whoever owns it, so an admin can see
     * what is failing without opening each account.
     */
    public function monitoring()
    {
        $monitors = \App\Models\Monitor::with(['user:id,name,email', 'collection:id,name', 'environment:id,name'])
            ->orderByRaw("last_status = 'failing' desc")
            ->orderBy('name')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'owner' => $m->user ? ['id' => $m->user->id, 'name' => $m->user->name, 'email' => $m->user->email] : null,
                'collection' => $m->collection?->name,
                'environment' => $m->environment?->name,
                'interval_minutes' => $m->interval_minutes,
                'is_enabled' => $m->is_enabled,
                'last_status' => $m->last_status,
                'last_run_at' => $m->last_run_at,
                'consecutive_failures' => $m->consecutive_failures,
                'uptime' => $m->uptime(),
            ]);

        return response()->json([
            'monitors' => $monitors,
            'summary' => [
                'total' => $monitors->count(),
                'failing' => $monitors->where('last_status', 'failing')->count(),
                'passing' => $monitors->where('last_status', 'passing')->count(),
                'disabled' => $monitors->where('is_enabled', false)->count(),
                // Alerting is silent when nothing is configured, so surface it.
                'alert_channels' => \App\Models\AlertChannel::count(),
            ],
        ]);
    }

    /**
     * One user in full, for the admin detail view: profile, membership, what
     * they have built, and their recent activity.
     */
    public function user(Request $request, int $id)
    {
        $user = User::withTrashed()
            ->with('organisation:id,name')
            ->withCount(['savedRequests', 'requestHistories', 'environments', 'collections', 'monitors'])
            ->findOrFail($id);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
                'email_verified' => $user->email_verified_at !== null,
                'signed_up_with_google' => $user->google_id !== null,
                // Never the key itself, only whether one is configured.
                'has_scx_key' => ! empty($user->scx_api_key),
                'has_api_key' => ! empty($user->api_token),
                'organisation' => $user->organisation
                    ? ['id' => $user->organisation->id, 'name' => $user->organisation->name]
                    : null,
                'created_at' => $user->created_at?->toDateTimeString(),
                'updated_at' => $user->updated_at?->toDateTimeString(),
                'deleted_at' => $user->deleted_at?->toDateTimeString(),
            ],
            'counts' => [
                'saved_requests' => $user->saved_requests_count,
                'request_histories' => $user->request_histories_count,
                'environments' => $user->environments_count,
                'collections' => $user->collections_count,
                'monitors' => $user->monitors_count,
            ],
            'recent_requests' => $user->requestHistories()
                ->latest('id')->take(10)
                ->get(['id', 'protocol', 'method', 'url', 'status', 'time_ms', 'created_at']),
            'monitors' => $user->monitors()
                ->with('collection:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'collection_id', 'last_status', 'last_run_at', 'is_enabled']),
        ]);
    }

    /**
     * Move a user into an organisation, or out of one with a null id.
     */
    public function assignOrganisation(Request $request, int $id)
    {
        $validated = $request->validate([
            'organisation_id' => 'nullable|integer|exists:organisations,id',
        ]);

        $user = User::findOrFail($id);
        $user->update(['organisation_id' => $validated['organisation_id'] ?? null]);

        AdminAction::create([
            'admin_id' => $request->user()->id,
            'admin_email' => $request->user()->email,
            'action' => $validated['organisation_id'] ? 'assign_organisation' : 'unassign_organisation',
            'target_user_id' => $user->id,
            'target_email' => $user->email,
        ]);

        return response()->json(['message' => 'Updated']);
    }

    public function toggleAdmin(Request $request, $id)
    {
        $user = User::findOrFail($id);

        // Prevent removing your own admin status
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot remove your own admin status.'], 422);
        }

        $user->is_admin = !$user->is_admin;
        $user->save();

        AdminAction::create([
            'admin_id' => $request->user()->id,
            'admin_email' => $request->user()->email,
            'action' => $user->is_admin ? 'promote_admin' : 'demote_admin',
            'target_user_id' => $user->id,
            'target_email' => $user->email,
        ]);

        return response()->json([
            'message' => $user->is_admin ? 'User promoted to admin.' : 'Admin privileges removed.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'is_admin' => $user->is_admin,
            ]
        ]);
    }

    /**
     * Delete a user.
     */
    /**
     * Create an account directly, for onboarding someone without waiting on
     * the email-verification flow.
     */
    public function storeUser(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            // email:filter, not the default rule, which accepts a quoted local
            // part containing CRLF (GHSA-5vg9-5847-vvmq).
            'email' => ['required', 'string', 'email:filter', 'max:255', Rule::unique('users', 'email')->withoutTrashed()],
            'password' => 'required|string|min:12',
            'is_admin' => 'nullable|boolean',
            'organisation_id' => 'nullable|integer|exists:organisations,id',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => strtolower($validated['email']),
            'password' => Hash::make($validated['password']),
            'is_admin' => (bool) ($validated['is_admin'] ?? false),
            'organisation_id' => $validated['organisation_id'] ?? null,
        ]);

        // Not mass-assignable, so it has to be set explicitly. Created by an
        // admin, so the address counts as already confirmed.
        $user->forceFill(['email_verified_at' => now()])->save();

        AdminAction::create([
            'admin_id' => $request->user()->id,
            'admin_email' => $request->user()->email,
            'action' => 'create_user',
            'target_user_id' => $user->id,
            'target_email' => $user->email,
            'details' => ['as_admin' => (bool) $user->is_admin],
        ]);

        return response()->json([
            'message' => 'User created.',
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ], 201);
    }

    /**
     * Soft delete: the account is locked out (soft-deleted users are excluded
     * from every query, including the auth provider) but everything they own
     * is kept, so a restore brings the account back intact.
     */
    public function deleteUser(Request $request, $id)
    {
        $user = User::findOrFail($id);

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot delete yourself.'], 422);
        }

        AdminAction::create([
            'admin_id' => $request->user()->id,
            'admin_email' => $request->user()->email,
            'action' => 'delete_user',
            'target_user_id' => $user->id,
            'target_email' => $user->email,
            'details' => [
                'name' => $user->name,
                'was_admin' => (bool) $user->is_admin,
                'mode' => 'soft',
            ],
        ]);

        $user->delete();

        return response()->json(['message' => 'User deactivated. Their data is kept and can be restored.']);
    }

    public function restoreUser(Request $request, $id)
    {
        $user = User::onlyTrashed()->findOrFail($id);

        $user->restore();

        AdminAction::create([
            'admin_id' => $request->user()->id,
            'admin_email' => $request->user()->email,
            'action' => 'restore_user',
            'target_user_id' => $user->id,
            'target_email' => $user->email,
        ]);

        return response()->json(['message' => 'User restored.']);
    }

    /**
     * Hard delete: the account and everything it owns, permanently.
     *
     * The owned tables all cascade on delete, so the database does the work;
     * the counts are gathered first purely so the audit entry records what
     * went. The audit log itself is deliberately NOT owned data — entries
     * about this user survive, and entries they wrote as an admin keep their
     * email snapshot.
     */
    public function forceDeleteUser(Request $request, $id)
    {
        $user = User::withTrashed()->findOrFail($id);

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot delete yourself.'], 422);
        }

        $deleted = [
            'saved_requests' => $user->savedRequests()->count(),
            'request_histories' => $user->requestHistories()->count(),
            'environments' => $user->environments()->count(),
            'collections' => $user->collections()->count(),
            'monitors' => $user->monitors()->count(),
            'alert_channels' => $user->alertChannels()->count(),
            'reports' => InspectionReport::where('user_id', $user->id)->count(),
        ];

        AdminAction::create([
            'admin_id' => $request->user()->id,
            'admin_email' => $request->user()->email,
            'action' => 'force_delete_user',
            'target_user_id' => $user->id,
            'target_email' => $user->email,
            'details' => [
                'name' => $user->name,
                'was_admin' => (bool) $user->is_admin,
                'mode' => 'hard',
                'deleted' => $deleted,
            ],
        ]);

        $user->forceDelete();

        return response()->json([
            'message' => 'User and all associated records permanently deleted.',
            'deleted' => $deleted,
        ]);
    }
}
