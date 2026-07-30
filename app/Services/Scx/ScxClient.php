<?php

namespace App\Services\Scx;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin wrapper around the SCX AI chat-completions API (OpenAI-compatible)
 * keyed by a user's personal, encrypted SCX API key. Centralises the call so
 * every AI-powered feature — request authoring, response explanation, the
 * security scanner, and the agent-in-the-loop runner — shares one code path
 * for auth, model selection, error handling, and JSON coercion.
 */
class ScxClient
{
    public const ENDPOINT = 'https://api.scx.ai/v1/chat/completions';

    public function __construct(
        protected string $apiKey,
        protected string $model = 'scx-ai',
    ) {
    }

    /**
     * Build a client for a user, or throw if they have no key configured.
     * Callers surface this as a 400 with an actionable message.
     */
    public static function forUser(User $user): self
    {
        if (empty($user->scx_api_key)) {
            throw new ScxKeyMissingException(
                'SCX API key not configured. Please add it in your Profile Settings.'
            );
        }

        return new self($user->scx_api_key, $user->scx_model ?? 'scx-ai');
    }

    /**
     * Send a chat completion and return the raw assistant message array,
     * which may contain 'content' and/or 'tool_calls'. Options accepted:
     *   - tools:       array of OpenAI-style function tool definitions
     *   - temperature: float (default 0.2 — these are analysis tasks)
     *   - max_tokens:  int (default 2000)
     *   - json:        bool — request a JSON object response
     */
    public function chat(array $messages, array $options = []): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'] ?? 2000,
            'temperature' => $options['temperature'] ?? 0.2,
        ];

        if (! empty($options['tools'])) {
            $payload['tools'] = $options['tools'];
        }

        if (! empty($options['json'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout($options['timeout'] ?? 60)->post(self::ENDPOINT, $payload);
        } catch (\Throwable $e) {
            Log::error('SCX API exception', ['message' => $e->getMessage()]);
            throw new RuntimeException('Failed to connect to SCX AI. Please check your API key and try again.');
        }

        if (! $response->successful()) {
            Log::error('SCX API error', ['status' => $response->status(), 'body' => $response->body()]);
            throw new RuntimeException('SCX AI service temporarily unavailable (HTTP '.$response->status().').');
        }

        $message = $response->json('choices.0.message');

        if (! is_array($message)) {
            throw new RuntimeException('SCX AI returned an unexpected response shape.');
        }

        return $message;
    }

    /**
     * Convenience: send messages and return the assistant text content.
     */
    public function complete(array $messages, array $options = []): string
    {
        return (string) ($this->chat($messages, $options)['content'] ?? '');
    }

    /**
     * Send messages expecting a JSON object back and return it decoded.
     * Tolerates models that wrap JSON in prose or ```json fences.
     */
    public function completeJson(array $messages, array $options = []): array
    {
        $options['json'] = true;
        $content = $this->complete($messages, $options);

        return self::extractJson($content);
    }

    /**
     * Pull the first JSON object/array out of a model response, coping with
     * ```json fences and surrounding chatter. Throws if none is parseable.
     */
    public static function extractJson(string $content): array
    {
        $content = trim($content);

        // Strip a leading ```json / ``` fence if present.
        if (preg_match('/```(?:json)?\s*(.*?)```/s', $content, $m)) {
            $content = trim($m[1]);
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Fall back to the first balanced {...} or [...] span.
        if (preg_match('/(\{.*\}|\[.*\])/s', $content, $m)) {
            $decoded = json_decode($m[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        throw new RuntimeException('SCX AI did not return valid JSON.');
    }
}
