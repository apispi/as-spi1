<?php

namespace App\Http\Controllers;

use App\Services\Scx\ScxClient;
use App\Services\Scx\ScxKeyMissingException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ScxChatController extends Controller
{
    public function chat(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:4000',
            'history' => 'nullable|array',
            'history.*.role' => 'required|in:user,assistant',
            'history.*.content' => 'required|string',
        ]);

        try {
            $client = ScxClient::forUser(Auth::user());
        } catch (ScxKeyMissingException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }

        $messages = [[
            'role' => 'system',
            'content' => 'You are SCX AI, a helpful coding assistant. You help users with API requests, code debugging, and general programming questions. Be concise and practical in your responses.',
        ]];

        foreach ($validated['history'] ?? [] as $msg) {
            $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }

        $messages[] = ['role' => 'user', 'content' => $validated['message']];

        try {
            $message = $client->chat($messages, ['temperature' => 0.7]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json([
            'response' => $message['content'] ?? 'I apologize, but I could not generate a response.',
        ]);
    }
}
