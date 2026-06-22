<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        $user = Auth::user();

        if (empty($user->scx_api_key)) {
            return response()->json(['error' => 'SCX API key not configured. Please add it in your Profile Settings.'], 400);
        }

        $messages = [];

        // Add system prompt
        $messages[] = [
            'role' => 'system',
            'content' => 'You are SCX AI, a helpful coding assistant. You help users with API requests, code debugging, and general programming questions. Be concise and practical in your responses.'
        ];

        // Add conversation history
        if (!empty($validated['history'])) {
            foreach ($validated['history'] as $msg) {
                $messages[] = [
                    'role' => $msg['role'],
                    'content' => $msg['content']
                ];
            }
        }

        // Add current message
        $messages[] = [
            'role' => 'user',
            'content' => $validated['message']
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $user->scx_api_key,
                'Content-Type' => 'application/json',
            ])->timeout(60)->post('https://api.scx.ai/v1/chat/completions', [
                'model' => $user->scx_model ?? 'scx-ai',
                'messages' => $messages,
                'max_tokens' => 2000,
                'temperature' => 0.7,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $assistantMessage = $data['choices'][0]['message']['content'] ?? 'I apologize, but I could not generate a response.';

                return response()->json([
                    'response' => $assistantMessage,
                    'usage' => $data['usage'] ?? null,
                ]);
            }

            Log::error('SCX API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return response()->json([
                'error' => 'SCX AI service temporarily unavailable. Please try again later.'
            ], 502);

        } catch (\Exception $e) {
            Log::error('SCX API exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to connect to SCX AI. Please check your API key and try again.'
            ], 503);
        }
    }
}