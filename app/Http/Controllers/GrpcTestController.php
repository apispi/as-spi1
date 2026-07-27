<?php

namespace App\Http\Controllers;

use App\Models\RequestHistory;
use App\Rules\PubliclyRoutableHost;
use App\Services\Grpc\GrpcClient;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

class GrpcTestController extends Controller
{
    public function test(Request $request, GrpcClient $client)
    {
        $validated = $request->validate([
            'host' => ['required', 'string', new PubliclyRoutableHost],
            'port' => 'nullable|integer|min:1|max:65535',
            'tls' => 'boolean',
            'tls_verify' => 'boolean',
            // "package.Service/Method" — fully-qualified method path.
            'service_method' => ['required', 'string', 'max:512', 'regex:#^/?[\w.]+/\w+$#'],
            'request' => 'nullable|array',
            'metadata' => 'nullable|array',
            'timeout' => 'nullable|integer|min:1|max:30',
        ]);

        $startTime = microtime(true);

        try {
            $result = $client->unary($validated);
            $timeTakenMs = round((microtime(true) - $startTime) * 1000);

            RequestHistory::record($request->user()->id, [
                'protocol' => 'grpc',
                'method' => $validated['service_method'],
                'url' => $validated['host'].':'.($validated['port'] ?? ($validated['tls'] ?? false ? 443 : 80)),
                'params' => $validated,
                'status' => $result['grpc_status'],
                'time_ms' => $timeTakenMs,
            ]);

            return response()->json($result + ['time_ms' => $timeTakenMs]);
        } catch (InvalidArgumentException $e) {
            // Malformed request-message field list — a client error.
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            $timeTakenMs = round((microtime(true) - $startTime) * 1000);

            RequestHistory::record($request->user()->id, [
                'protocol' => 'grpc',
                'method' => $validated['service_method'],
                'url' => $validated['host'].':'.($validated['port'] ?? ''),
                'params' => $validated,
                'status' => null,
                'time_ms' => $timeTakenMs,
            ]);

            return response()->json([
                'error' => 'gRPC Error: '.$e->getMessage(),
                'time_ms' => $timeTakenMs,
            ], 502);
        }
    }
}
