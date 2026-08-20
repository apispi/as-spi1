<?php

namespace App\Http\Controllers;

use App\Services\Assertions\Assertion;
use App\Services\Assertions\AssertionEvaluator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssertionController extends Controller
{
    /**
     * Cap per request so a pathological suite cannot tie up a worker.
     */
    public const MAX_ASSERTIONS = 50;

    public function __construct(private readonly AssertionEvaluator $evaluator)
    {
    }

    /**
     * Evaluate assertions against a response the caller already has.
     *
     * Kept separate from the tester endpoints so the same evaluation serves
     * the tester, a saved request, and (later) a collection run, without any
     * of them re-implementing it.
     */
    public function evaluate(Request $request)
    {
        $validated = $request->validate([
            'assertions' => 'required|array|min:1|max:'.self::MAX_ASSERTIONS,
            'response' => 'required|array',
            'response.status' => 'nullable|integer',
            'response.time_ms' => 'nullable|numeric',
            'response.headers' => 'nullable|array',
            'response.body' => 'nullable',
        ] + $this->assertionRules());

        return response()->json(
            $this->evaluator->evaluate($validated['assertions'], $validated['response'])
        );
    }

    /**
     * Replace the assertions attached to a saved request.
     */
    public function update(Request $request, int $id)
    {
        $saved = $request->user()->savedRequests()->findOrFail($id);

        $validated = $request->validate([
            'assertions' => 'present|array|max:'.self::MAX_ASSERTIONS,
        ] + $this->assertionRules());

        $saved->update(['assertions' => array_values($validated['assertions'])]);

        return response()->json($saved->fresh());
    }

    /**
     * Shared shape rules. The operator list is closed so that anything stored
     * is guaranteed evaluable.
     */
    private function assertionRules(): array
    {
        return [
            'assertions.*.path' => 'required|string|max:255',
            'assertions.*.operator' => ['required', 'string', Rule::in(Assertion::operators())],
            'assertions.*.expected' => 'nullable',
            'assertions.*.description' => 'nullable|string|max:255',
        ];
    }
}
