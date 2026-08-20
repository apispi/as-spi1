<?php

namespace App\Services\Collections;

use App\Rules\PubliclyRoutableUrl;
use App\Services\A2a\A2aClient;
use App\Services\Mcp\McpClient;
use App\Services\Security\SsrfException;
use App\Services\Security\SsrfGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Sends a single already-resolved request and returns it in the shape the
 * assertion evaluator understands: {status, time_ms, headers, body}.
 *
 * The tester endpoints each do this inline for their own protocol; the runner
 * needs it without an HTTP round-trip, so the shared behaviour — SSRF
 * validation, IP pinning, no redirect following — lives here.
 *
 * Socket-based protocols (gRPC, MQTT, AMQP) are deliberately out of scope for
 * runs: they need per-connection credentials that a collection has no way to
 * supply yet. Rather than half-run them, a step naming one is reported as
 * unsupported.
 */
class RequestExecutor
{
    public const SUPPORTED = ['rest', 'mcp', 'a2a'];

    /**
     * @param  array  $request  {protocol, method, url, headers, body, params}
     * @return array{ok: bool, status: int|null, time_ms: int, headers: array, body: mixed, error: string|null}
     */
    public function send(array $request): array
    {
        $protocol = $request['protocol'] ?? 'rest';

        if (! in_array($protocol, self::SUPPORTED, true)) {
            return $this->failure("The {$protocol} protocol cannot run in a collection yet.", 0);
        }

        $url = (string) ($request['url'] ?? '');

        if ($error = $this->validateUrl($url)) {
            return $this->failure($error, 0);
        }

        $headers = collect($request['headers'] ?? [])
            ->filter(fn ($v, $k) => ! in_array(strtolower((string) $k), ['host', 'content-length']))
            ->all();

        $started = microtime(true);

        try {
            return match ($protocol) {
                'mcp' => $this->mcp($url, $request, $headers, $started),
                'a2a' => $this->a2a($url, $request, $headers, $started),
                default => $this->rest($url, $request, $headers, $started),
            };
        } catch (Throwable $e) {
            return $this->failure($e->getMessage(), $this->elapsed($started));
        }
    }

    /**
     * Validate the resolved URL, including the SSRF guard — a collection step
     * gets exactly the same protection as an interactive request.
     */
    private function validateUrl(string $url): ?string
    {
        if ($url === '') {
            return 'The request has no URL.';
        }

        $validator = Validator::make(
            ['url' => $url],
            ['url' => ['required', 'url', new PubliclyRoutableUrl]]
        );

        return $validator->fails() ? $validator->errors()->first('url') : null;
    }

    private function rest(string $url, array $request, array $headers, float $started): array
    {
        $method = strtoupper($request['method'] ?? 'GET');
        $body = $request['body'] ?? null;

        if (is_array($body)) {
            $body = json_encode($body);
        }

        // Pin the validated IP so the host cannot re-resolve inward between
        // validation and connection.
        try {
            $pinned = (new SsrfGuard)->pinnedOptions($url);
        } catch (SsrfException $e) {
            return $this->failure($e->getMessage(), $this->elapsed($started));
        }

        $pending = Http::withHeaders($headers)
            ->withoutVerifying()
            ->withOptions(['allow_redirects' => false] + $pinned);

        $response = in_array($method, ['POST', 'PUT', 'PATCH']) && ! empty($body)
            ? $pending->send($method, $url, ['body' => $body])
            : $pending->send($method, $url);

        return [
            'ok' => true,
            'status' => $response->status(),
            'time_ms' => $this->elapsed($started),
            'headers' => $response->headers(),
            'body' => $response->body(),
            'error' => null,
        ];
    }

    private function mcp(string $url, array $request, array $headers, float $started): array
    {
        $client = new McpClient($url, null, $headers);
        $method = $request['method'] ?? 'initialize';

        $init = $client->initialize();
        $result = $method === 'initialize'
            ? $init
            : $client->request($method, $request['params'] ?? []);

        return [
            'ok' => true,
            'status' => 200,
            'time_ms' => $this->elapsed($started),
            'headers' => array_filter([
                'Mcp-Session-Id' => $client->sessionId(),
                'Mcp-Protocol-Version' => $client->protocolVersion(),
            ]),
            'body' => json_encode($result),
            'error' => null,
        ];
    }

    private function a2a(string $url, array $request, array $headers, float $started): array
    {
        $client = new A2aClient($url, null, $headers);
        $method = $request['method'] ?? 'agent-card';

        $result = $method === 'agent-card'
            ? $client->getAgentCard()
            : $client->request($method, $request['params'] ?? []);

        return [
            'ok' => true,
            'status' => 200,
            'time_ms' => $this->elapsed($started),
            'headers' => [],
            'body' => json_encode($result),
            'error' => null,
        ];
    }

    private function failure(string $error, int $timeMs): array
    {
        return [
            'ok' => false,
            'status' => null,
            'time_ms' => $timeMs,
            'headers' => [],
            'body' => null,
            'error' => $error,
        ];
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
