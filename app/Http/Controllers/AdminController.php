<?php

namespace App\Http\Controllers;

use App\Models\AdminAction;
use App\Models\SavedRequest;
use App\Models\User;
use Illuminate\Http\Request;

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
        ]);

        $query = User::withCount('savedRequests')->orderBy('created_at', 'desc');

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
                'saved_requests_count' => $user->saved_requests_count,
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
        return response()->json([
            'total_users' => User::count(),
            'admin_users' => User::where('is_admin', true)->count(),
            'total_saved_requests' => SavedRequest::count(),
            'new_users_this_week' => User::where('created_at', '>=', now()->subWeek())->count(),
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
    public function deleteUser(Request $request, $id)
    {
        $user = User::findOrFail($id);

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot delete yourself.'], 422);
        }

        $savedRequestCount = $user->savedRequests()->count();

        AdminAction::create([
            'admin_id' => $request->user()->id,
            'action' => 'delete_user',
            'target_user_id' => $user->id,
            'target_email' => $user->email,
            'details' => [
                'name' => $user->name,
                'was_admin' => (bool) $user->is_admin,
                'saved_requests_deleted' => $savedRequestCount,
            ],
        ]);

        $user->delete();

        return response()->json(['message' => 'User deleted.']);
    }
}
