<?php

namespace App\Services\Grpc;

use App\Services\Security\SsrfGuard;
use RuntimeException;

/**
 * A pure-PHP gRPC client for unary calls, for the tester. gRPC is HTTP/2 with
 * length-prefixed protobuf frames and the real call status carried in HTTP/2
 * trailers; this handles that framing over curl's HTTP/2 support.
 *
 * Scope: unary (single request → single response) only. Client/server
 * streaming is out of scope for a synchronous request. Messages are described
 * as explicit field lists (see ProtobufCodec) rather than compiled from .proto.
 */
class GrpcClient
{
    /** @var array<int, string> */
    protected const STATUS_NAMES = [
        0 => 'OK', 1 => 'CANCELLED', 2 => 'UNKNOWN', 3 => 'INVALID_ARGUMENT',
        4 => 'DEADLINE_EXCEEDED', 5 => 'NOT_FOUND', 6 => 'ALREADY_EXISTS',
        7 => 'PERMISSION_DENIED', 8 => 'RESOURCE_EXHAUSTED', 9 => 'FAILED_PRECONDITION',
        10 => 'ABORTED', 11 => 'OUT_OF_RANGE', 12 => 'UNIMPLEMENTED', 13 => 'INTERNAL',
        14 => 'UNAVAILABLE', 15 => 'DATA_LOSS', 16 => 'UNAUTHENTICATED',
    ];

    /**
     * @param  (callable(string, array<int,string>, string, bool, bool, int): array{status:int, headers:array<string,string>, body:string})|null  $transport
     *   Optional transport (url, headers, body, tls, verify, timeout) => response, for testing.
     */
    public function __construct(
        protected ProtobufCodec $codec = new ProtobufCodec,
        protected $transport = null,
        protected ?SsrfGuard $guard = null,
    ) {
        $this->guard ??= new SsrfGuard;
    }

    /**
     * @param  array{
     *   host: string, port?: int, tls?: bool, tls_verify?: bool,
     *   service_method: string, request?: array<int, array>,
     *   metadata?: array<string, string>, timeout?: int
     * }  $opts
     * @return array<string, mixed>
     */
    public function unary(array $opts): array
    {
        $tls = (bool) ($opts['tls'] ?? false);
        $host = $opts['host'];
        $port = (int) ($opts['port'] ?? ($tls ? 443 : 80));
        $path = '/'.ltrim($opts['service_method'], '/');
        $timeout = max(1, min(30, (int) ($opts['timeout'] ?? 10)));
        $url = ($tls ? 'https' : 'http').'://'.$host.':'.$port.$path;

        $message = $this->codec->encode($opts['request'] ?? []);
        $frame = chr(0).pack('N', strlen($message)).$message;

        $headers = [
            'content-type: application/grpc+proto',
            'te: trailers',
            'grpc-timeout: '.($timeout * 1000).'m',
        ];
        foreach (($opts['metadata'] ?? []) as $key => $value) {
            $k = strtolower(trim((string) $key));
            if ($k !== '' && ! in_array($k, ['content-type', 'te', 'host'], true)) {
                $headers[] = $k.': '.$value;
            }
        }

        // Pin the validated address for this host:port so cURL cannot
        // re-resolve the name between validation and connection. gRPC rides on
        // cURL, so this is the same CURLOPT_RESOLVE pin the HTTP testers use.
        $pin = $this->guard->pinnedOptions($url);

        $response = $this->send($url, $headers, $frame, $tls, (bool) ($opts['tls_verify'] ?? true), $timeout, $pin);

        return $this->parseResponse($response, $opts['service_method']);
    }

    /**
     * @param  array{status:int, headers:array<string,string>, body:string}  $response
     * @return array<string, mixed>
     */
    protected function parseResponse(array $response, string $method): array
    {
        $meta = $response['headers'];
        $grpcStatus = isset($meta['grpc-status']) ? (int) $meta['grpc-status'] : null;
        $grpcMessage = isset($meta['grpc-message']) ? rawurldecode($meta['grpc-message']) : null;

        $frames = $this->parseFrames($response['body']);
        $payload = $frames[0] ?? null;

        // A 200 with no grpc-status trailer still counts as OK per spec.
        $effectiveStatus = $grpcStatus ?? ($response['status'] === 200 ? 0 : 2);

        return [
            'ok' => $effectiveStatus === 0,
            'method' => $method,
            'http_status' => $response['status'],
            'grpc_status' => $effectiveStatus,
            'grpc_status_name' => self::STATUS_NAMES[$effectiveStatus] ?? 'UNKNOWN',
            'grpc_message' => $grpcMessage,
            'response' => $payload !== null ? $this->codec->decode($payload) : null,
            'response_base64' => $payload !== null ? base64_encode($payload) : null,
            'metadata' => $meta,
        ];
    }

    /**
     * Split a gRPC-framed body (1 byte compression flag + 4-byte big-endian
     * length + message) into its messages.
     *
     * @return array<int, string>
     */
    protected function parseFrames(string $body): array
    {
        $messages = [];
        $offset = 0;
        $len = strlen($body);

        while ($offset + 5 <= $len) {
            $length = unpack('N', substr($body, $offset + 1, 4))[1];
            $offset += 5;
            if ($offset + $length > $len) {
                break;
            }
            $offset += $length;
            // Compressed frames need a decoder we do not carry, but we still
            // surface the raw bytes so the caller sees something.
            $messages[] = substr($body, $offset - $length, $length);
        }

        return $messages;
    }

    /**
     * @param  array<int, string>  $headers
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    protected function send(string $url, array $headers, string $body, bool $tls, bool $verify, int $timeout, array $pin = []): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($url, $headers, $body, $tls, $verify, $timeout);
        }

        if (! function_exists('curl_init')) {
            throw new RuntimeException('The gRPC tester requires the curl extension.');
        }

        $ch = curl_init($url);
        $respHeaders = [];

        $http2 = $tls
            ? CURL_HTTP_VERSION_2TLS
            : (defined('CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE') ? CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE : CURL_HTTP_VERSION_2_0);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTP_VERSION => $http2,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            // Pinned address for this host:port, when the guard supplied one.
            ...($pin['curl'] ?? []),
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
                $trimmed = trim($line);
                if ($trimmed !== '' && str_contains($trimmed, ':')) {
                    [$k, $v] = explode(':', $trimmed, 2);
                    $respHeaders[strtolower(trim($k))] = trim($v);
                }

                return strlen($line);
            },
        ]);

        $responseBody = curl_exec($ch);

        if ($responseBody === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('gRPC transport error: '.$error);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'headers' => $respHeaders, 'body' => (string) $responseBody];
    }
}
