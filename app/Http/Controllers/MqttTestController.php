<?php

namespace App\Http\Controllers;

use App\Models\RequestHistory;
use App\Rules\PubliclyRoutableHost;
use App\Services\Mqtt\MqttTester;
use Illuminate\Http\Request;
use Throwable;

class MqttTestController extends Controller
{
    public function test(Request $request, MqttTester $tester)
    {
        $validated = $request->validate([
            'host' => ['required', 'string', new PubliclyRoutableHost],
            'port' => 'nullable|integer|min:1|max:65535',
            'tls' => 'boolean',
            'tls_verify' => 'boolean',
            'username' => 'nullable|string',
            'password' => 'nullable|string',
            'client_id' => 'nullable|string|max:128',
            'action' => 'required|string|in:publish,subscribe,publish_subscribe',
            'topic' => 'required|string|max:512',
            'message' => 'nullable|string',
            'qos' => 'nullable|integer|min:0|max:2',
            'retain' => 'boolean',
            'timeout' => 'nullable|integer|min:1|max:'.MqttTester::MAX_TIMEOUT,
            'max_messages' => 'nullable|integer|min:1|max:'.MqttTester::MAX_MESSAGES,
        ]);

        $startTime = microtime(true);

        try {
            $result = $tester->run($validated);
            $timeTakenMs = round((microtime(true) - $startTime) * 1000);

            RequestHistory::record($request->user()->id, [
                'protocol' => 'mqtt',
                'method' => $validated['action'].' '.$validated['topic'],
                'url' => $result['broker'],
                'params' => $validated,
                'status' => 200,
                'time_ms' => $timeTakenMs,
            ]);

            return response()->json($result + ['time_ms' => $timeTakenMs]);
        } catch (Throwable $e) {
            $timeTakenMs = round((microtime(true) - $startTime) * 1000);

            RequestHistory::record($request->user()->id, [
                'protocol' => 'mqtt',
                'method' => $validated['action'].' '.$validated['topic'],
                'url' => ($validated['host']).':'.($validated['port'] ?? ''),
                'params' => $validated,
                'status' => null,
                'time_ms' => $timeTakenMs,
            ]);

            return response()->json([
                'error' => 'MQTT Error: '.$e->getMessage(),
                'time_ms' => $timeTakenMs,
            ], 502);
        }
    }
}
