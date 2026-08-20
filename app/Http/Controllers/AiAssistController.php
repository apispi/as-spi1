<?php

namespace App\Http\Controllers;

use App\Services\Assertions\Assertion;
use App\Services\Scx\ScxClient;
use App\Services\Scx\ScxKeyMissingException;
use Illuminate\Http\Request;
use Throwable;

/**
 * AI-assisted request authoring for the tester, powered by the caller's SCX
 * key. Four actions, each a focused LLM call with a strict JSON contract:
 *   - author():  natural language + optional schema  -> a ready-to-send request
 *   - explain(): a request/response/error            -> a plain-English summary
 *   - assert():  a response body                     -> suggested assertions
 *   - fix():     a failing request + error           -> a corrected request
 */
class AiAssistController extends Controller
{
    /**
     * Turn a natural-language instruction into a concrete request. When a
     * schema (e.g. an MCP tool inputSchema or OpenAPI operation) is supplied,
     * arguments are shaped to fit it.
     */
    public function author(Request $request)
    {
        $v = $request->validate([
            'instruction' => 'required|string|max:2000',
            'protocol' => 'required|string|max:20',
            'schema' => 'nullable',
            'context' => 'nullable|string|max:8000',
        ]);

        $sys = 'You convert a natural-language instruction into a single concrete API request for the '
            .$v['protocol'].' protocol. '
            .'Respond ONLY as JSON with this shape: '
            .'{"method":string,"url":string|null,"params":object,"body":string|null,"headers":object,"notes":string}. '
            .'Use null or empty values for fields that do not apply. Never invent secrets or auth tokens.';

        $user = "Instruction: {$v['instruction']}";
        if (! empty($v['schema'])) {
            $user .= "\n\nTarget schema (shape arguments to match):\n".(is_string($v['schema']) ? $v['schema'] : json_encode($v['schema']));
        }
        if (! empty($v['context'])) {
            $user .= "\n\nAdditional context:\n".$v['context'];
        }

        return $this->respondJson($request, $sys, $user, 0.1);
    }

    /**
     * Explain a request/response/error in plain English: what happened, why,
     * and what to try next.
     */
    public function explain(Request $request)
    {
        $v = $request->validate([
            'request' => 'nullable|string|max:20000',
            'response' => 'nullable|string|max:40000',
            'status' => 'nullable',
            'error' => 'nullable|string|max:8000',
        ]);

        $sys = 'You explain an API exchange to a developer. Be concise and practical. '
            .'Respond ONLY as JSON: {"summary":string,"likely_cause":string|null,"suggestions":[string]}.';

        $user = $this->exchangeBlock($v);

        return $this->respondJson($request, $sys, $user, 0.2);
    }

    /**
     * Suggest assertions/tests for a response body so the request can be
     * turned into a repeatable check.
     */
    public function assert(Request $request)
    {
        $v = $request->validate([
            'response' => 'required|string|max:40000',
            'status' => 'nullable',
        ]);

        // The operator list is closed and shared with the evaluator, so every
        // generated assertion is guaranteed to be runnable. Left open, the
        // model emits "contains", "includes", and "has" interchangeably.
        $operators = implode('|', Assertion::operators());
        $types = implode('|', Assertion::TYPES);

        $sys = 'You generate assertions that validate an API response. Prefer stable, meaningful checks '
            .'(status code, presence and type of key fields, invariants) over brittle exact-value matches. '
            ."The `operator` MUST be one of: {$operators}. "
            .'The `path` is "status", "time_ms", "header.<name>", or a dot path into the JSON body '
            .'(e.g. "data.items.0.id"). '
            ."For is_type, `expected` must be one of: {$types}. "
            .'For exists and not_exists, set `expected` to null. '
            .'Respond ONLY as JSON: {"assertions":[{"path":string,"operator":string,"expected":string|null,"description":string}]}.';

        $user = "Status: ".($v['status'] ?? 'unknown')."\n\nResponse body:\n".$v['response'];

        return $this->respondJson($request, $sys, $user, 0.1);
    }

    /**
     * Given a failing request and its error, propose a corrected request.
     */
    public function fix(Request $request)
    {
        $v = $request->validate([
            'request' => 'required|string|max:20000',
            'error' => 'required|string|max:8000',
            'protocol' => 'nullable|string|max:20',
            'response' => 'nullable|string|max:20000',
        ]);

        $sys = 'You diagnose why an API request failed and propose a corrected version. '
            .'Respond ONLY as JSON: {"diagnosis":string,"fixed_request":{"method":string|null,"url":string|null,'
            .'"params":object,"body":string|null,"headers":object},"changes":[string]}. '
            .'Do not fabricate credentials; if auth is missing, say so in changes instead.';

        $user = "Protocol: ".($v['protocol'] ?? 'unknown')."\n\nFailing request:\n".$v['request']
            ."\n\nError:\n".$v['error']
            .(! empty($v['response']) ? "\n\nResponse body:\n".$v['response'] : '');

        return $this->respondJson($request, $sys, $user, 0.1);
    }

    private function exchangeBlock(array $v): string
    {
        $parts = [];
        if (! empty($v['request'])) {
            $parts[] = "Request:\n".$v['request'];
        }
        $parts[] = "Status: ".($v['status'] ?? 'unknown');
        if (! empty($v['response'])) {
            $parts[] = "Response:\n".$v['response'];
        }
        if (! empty($v['error'])) {
            $parts[] = "Error:\n".$v['error'];
        }

        return implode("\n\n", $parts);
    }

    /**
     * Shared plumbing: build the SCX client, run a JSON completion, and map
     * failures to consistent HTTP responses.
     */
    private function respondJson(Request $request, string $system, string $user, float $temperature)
    {
        try {
            $client = ScxClient::forUser($request->user());
        } catch (ScxKeyMissingException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }

        try {
            $result = $client->completeJson([
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ], ['temperature' => $temperature, 'max_tokens' => 1800]);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json($result);
    }
}
