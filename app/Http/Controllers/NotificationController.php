<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use Illuminate\Http\Request;

/**
 * The current user's in-app notifications (the top-bar bell).
 */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $notes = UserNotification::where('user_id', $request->user()->id)
            ->latest('id')
            ->limit(30)
            ->get(['id', 'type', 'title', 'body', 'url', 'read_at', 'created_at']);

        return response()->json([
            'notifications' => $notes,
            'unread' => $notes->whereNull('read_at')->count(),
        ]);
    }

    public function unreadCount(Request $request)
    {
        return response()->json([
            'unread' => UserNotification::where('user_id', $request->user()->id)->whereNull('read_at')->count(),
        ]);
    }

    public function markRead(Request $request, int $id)
    {
        UserNotification::where('user_id', $request->user()->id)->where('id', $id)
            ->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['message' => 'Marked read.']);
    }

    public function markAllRead(Request $request)
    {
        UserNotification::where('user_id', $request->user()->id)
            ->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['message' => 'All marked read.']);
    }

    /** Full paginated history for the dedicated notifications page. */
    public function history(Request $request)
    {
        $notes = UserNotification::where('user_id', $request->user()->id)
            ->latest('id')
            ->paginate(20, ['id', 'type', 'title', 'body', 'url', 'read_at', 'created_at']);

        return response()->json($notes);
    }

    public function preferences(Request $request)
    {
        $prefs = $request->user()->notification_prefs ?? [];

        return response()->json(collect(\App\Models\User::NOTIFICATION_CATEGORIES)
            ->mapWithKeys(fn ($c) => [$c => ($prefs[$c] ?? true) !== false]));
    }

    public function updatePreferences(Request $request)
    {
        $rules = collect(\App\Models\User::NOTIFICATION_CATEGORIES)
            ->mapWithKeys(fn ($c) => [$c => 'required|boolean'])->all();
        $validated = $request->validate($rules);

        $request->user()->forceFill(['notification_prefs' => $validated])->save();

        return response()->json($validated);
    }
}
