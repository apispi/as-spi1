<?php

namespace App\Services\A2a;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal Agent2Agent (A2A) protocol client for manually exercising a
 * remote A2A agent during development.
 *
 * Protocol reference: https://a2a-protocol.org/latest/specification/
 * A2A calls are JSON-RPC 2.0 over HTTP, with agent capabilities published
 * at a well-known "Agent Card" document.
 */
class A2aClient
{
    protected int $nextId = 1;

    public function __construct(
        protected string $endpoint,
        protected ?string $bearerToken = null,
        protected array $extraHeaders = [],
    ) {
    }

    /**
     * Fetch the agent's public capabilities document. Tries the current
     * spec path first, then falls back to the older path for compatibility.
     */
    public function getAgentCard(): array
    {
        $base = rtrim(preg_replace('#/+$#', '', $this->baseUrl()), '/');

        foreach (['/.well-known/agent-card.json', '/.well-known/agent.json'] as $path) {
            $response = $this->client()->get($base.$path);

            if ($response->successful() && is_array($response->json())) {
                return $response->json();
            }
        }

        throw new RuntimeException('Could not fetch an Agent Card from '.$base.' (tried agent-card.json and agent.json).');
    }

    public function sendMessage(array $message, array $configuration = []): array
    {
        return $this->request('message/send', array_filter([
            'message' => $message,
            'configuration' => $configuration ?: null,
        ]));
    }

    public function getTask(string $taskId): array
    {
        return $this->request('tasks/get', ['id' => $taskId]);
    }

    public function cancelTask(string $taskId): array
    {
        return $this->request('tasks/cancel', ['id' => $taskId]);
    }

    /**
     * Send an arbitrary JSON-RPC request to the agent's endpoint and return
     * the "result" payload.
     */
    public function request(string $method, array $params = []): array
    {
        $id = $this->nextId++;

        $response = $this->client()->post($this->endpoint, [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => (object) $params,
        ]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'A2A agent responded with HTTP %d: %s',
                $response->status(),
                $response->body(),
            ));
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException('A2A agent returned a non-JSON response: '.$response->body());
        }

        if (isset($body['error'])) {
            throw new RuntimeException(sprintf(
                'A2A agent returned error %d: %s',
                $body['error']['code'] ?? 0,
                $body['error']['message'] ?? 'unknown error',
            ));
        }

        return $body['result'] ?? [];
    }

    protected function baseUrl(): string
    {
        $parts = parse_url($this->endpoint);

        if (! $parts || ! isset($parts['scheme'], $parts['host'])) {
            return $this->endpoint;
        }

        $base = $parts['scheme'].'://'.$parts['host'];

        if (isset($parts['port'])) {
            $base .= ':'.$parts['port'];
        }

        return $base;
    }

    protected function client()
    {
        $client = Http::acceptJson()
            ->withHeaders(array_merge([
                'Content-Type' => 'application/json',
            ], $this->extraHeaders));

        if ($this->bearerToken) {
            $client = $client->withToken($this->bearerToken);
        }

        return $client;
    }
}
