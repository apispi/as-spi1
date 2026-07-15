<?php

namespace App\Services\Mcp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal MCP (Model Context Protocol) client over the Streamable HTTP
 * transport, for manually exercising a remote MCP server during development.
 *
 * Protocol reference: https://modelcontextprotocol.io/specification
 */
class McpClient
{
    protected int $nextId = 1;

    protected ?string $sessionId = null;

    protected ?string $protocolVersion = null;

    public function __construct(
        protected string $endpoint,
        protected ?string $bearerToken = null,
        protected array $extraHeaders = [],
    ) {
    }

    public function initialize(string $clientName = 'as-spi1-mcp-test-client', string $clientVersion = '0.1.0'): array
    {
        $result = $this->request('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => [
                'name' => $clientName,
                'version' => $clientVersion,
            ],
        ]);

        $this->protocolVersion = $result['protocolVersion'] ?? null;

        // Per spec, the client must send an "initialized" notification
        // before issuing any further requests.
        $this->notify('notifications/initialized');

        return $result;
    }

    public function listTools(): array
    {
        return $this->request('tools/list');
    }

    public function callTool(string $name, array $arguments = []): array
    {
        return $this->request('tools/call', [
            'name' => $name,
            'arguments' => (object) $arguments,
        ]);
    }

    public function listResources(): array
    {
        return $this->request('resources/list');
    }

    public function readResource(string $uri): array
    {
        return $this->request('resources/read', ['uri' => $uri]);
    }

    public function listPrompts(): array
    {
        return $this->request('prompts/list');
    }

    public function getPrompt(string $name, array $arguments = []): array
    {
        return $this->request('prompts/get', [
            'name' => $name,
            'arguments' => (object) $arguments,
        ]);
    }

    public function ping(): array
    {
        return $this->request('ping');
    }

    /**
     * Send an arbitrary JSON-RPC request and return the "result" payload.
     */
    public function request(string $method, array $params = []): array
    {
        $id = $this->nextId++;

        $response = $this->send([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => (object) $params,
        ]);

        $message = $this->extractMessage($response, $id);

        if (isset($message['error'])) {
            throw new RuntimeException(sprintf(
                'MCP server returned error %d: %s',
                $message['error']['code'] ?? 0,
                $message['error']['message'] ?? 'unknown error',
            ));
        }

        return $message['result'] ?? [];
    }

    /**
     * Send a JSON-RPC notification (no response expected).
     */
    public function notify(string $method, array $params = []): void
    {
        $this->send([
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => (object) $params,
        ]);
    }

    protected function send(array $payload): Response
    {
        $response = $this->client()->post($this->endpoint, $payload);

        if ($sessionId = $response->header('Mcp-Session-Id')) {
            $this->sessionId = $sessionId;
        }

        if ($response->failed() && $response->status() !== 202) {
            throw new RuntimeException(sprintf(
                'MCP server responded with HTTP %d: %s',
                $response->status(),
                $response->body(),
            ));
        }

        return $response;
    }

    protected function client(): PendingRequest
    {
        $client = Http::acceptJson()
            ->withOptions(['allow_redirects' => false])
            ->withHeaders(array_merge([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json, text/event-stream',
            ], $this->extraHeaders));

        if ($this->bearerToken) {
            $client = $client->withToken($this->bearerToken);
        }

        if ($this->sessionId) {
            $client = $client->withHeaders(['Mcp-Session-Id' => $this->sessionId]);
        }

        return $client;
    }

    /**
     * Streamable HTTP responses are either a single JSON object, or a
     * text/event-stream carrying one or more JSON-RPC messages as "data:"
     * lines. Find the message matching the given request id.
     */
    protected function extractMessage(Response $response, int $id): array
    {
        $contentType = $response->header('Content-Type');

        if ($contentType && str_contains($contentType, 'text/event-stream')) {
            foreach ($this->parseEventStream($response->body()) as $message) {
                if (($message['id'] ?? null) === $id) {
                    return $message;
                }
            }

            throw new RuntimeException("No matching JSON-RPC response found in SSE stream for request id {$id}.");
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException('MCP server returned a non-JSON response: '.$response->body());
        }

        return $body;
    }

    protected function parseEventStream(string $body): array
    {
        $messages = [];

        foreach (explode("\n\n", $body) as $event) {
            $data = '';

            foreach (explode("\n", $event) as $line) {
                if (str_starts_with($line, 'data:')) {
                    $data .= ltrim(substr($line, 5))."\n";
                }
            }

            $data = trim($data);

            if ($data === '') {
                continue;
            }

            $decoded = json_decode($data, true);

            if (is_array($decoded)) {
                $messages[] = $decoded;
            }
        }

        return $messages;
    }

    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    public function protocolVersion(): ?string
    {
        return $this->protocolVersion;
    }
}
