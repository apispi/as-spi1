<?php

namespace App\Http\Controllers;

use App\Models\RequestHistory;
use App\Rules\PubliclyRoutableHost;
use App\Services\Amqp\AmqpTester;
use Illuminate\Http\Request;
use Throwable;

class AmqpTestController extends Controller
{
    public function test(Request $request, AmqpTester $tester)
    {
        $validated = $request->validate([
            'host' => ['required', 'string', new PubliclyRoutableHost],
            'port' => 'nullable|integer|min:1|max:65535',
            'tls' => 'boolean',
            'tls_verify' => 'boolean',
            'username' => 'nullable|string',
            'password' => 'nullable|string',
            'vhost' => 'nullable|string|max:255',
            'action' => 'required|string|in:publish,get,publish_get',
            'exchange' => 'nullable|string|max:255',
            'routing_key' => 'nullable|string|max:255',
            'queue' => 'required_if:action,get,publish_get|nullable|string|max:255',
            'message' => 'nullable|string',
            'auto_ack' => 'boolean',
            'timeout' => 'nullable|integer|min:1|max:15',
            'max_messages' => 'nullable|integer|min:1|max:'.AmqpTester::MAX_MESSAGES,
        ]);

        $startTime = microtime(true);
        $method = trim($validated['action'].' '.($validated['routing_key'] ?? $validated['queue'] ?? ''));

        try {
            $result = $tester->run($validated);
            $timeTakenMs = round((microtime(true) - $startTime) * 1000);

            RequestHistory::record($request->user()->id, [
                'protocol' => 'amqp',
                'method' => $method,
                'url' => $result['broker'],
                'params' => $validated,
                'status' => 200,
                'time_ms' => $timeTakenMs,
            ]);

            return response()->json($result + ['time_ms' => $timeTakenMs]);
        } catch (Throwable $e) {
            $timeTakenMs = round((microtime(true) - $startTime) * 1000);

            RequestHistory::record($request->user()->id, [
                'protocol' => 'amqp',
                'method' => $method,
                'url' => ($validated['host']).':'.($validated['port'] ?? ''),
                'params' => $validated,
                'status' => null,
                'time_ms' => $timeTakenMs,
            ]);

            return response()->json([
                'error' => 'AMQP Error: '.$e->getMessage(),
                'time_ms' => $timeTakenMs,
            ], 502);
        }
    }
}
