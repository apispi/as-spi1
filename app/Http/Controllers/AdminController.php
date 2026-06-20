<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;

class AdminController extends Controller
{
    /**
     * List all users with their status information.
     */
    public function users(Request $request)
    {
        $users = User::withCount('savedRequests')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_admin' => $user->is_admin,
                    'saved_requests_count' => $user->saved_requests_count,
                    'created_at' => $user->created_at->toDateTimeString(),
                    'updated_at' => $user->updated_at->toDateTimeString(),
                ];
            });

        return response()->json($users);
    }

    /**
     * Get dashboard stats.
     */
    public function stats(Request $request)
    {
        return response()->json([
            'total_users' => User::count(),
            'admin_users' => User::where('is_admin', true)->count(),
            'total_saved_requests' => \App\Models\SavedRequest::count(),
        ]);
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

        $user->delete();

        return response()->json(['message' => 'User deleted.']);
    }
}
