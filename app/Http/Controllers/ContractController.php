<?php

namespace App\Http\Controllers;

use App\Models\SavedRequest;
use App\Services\Contracts\ContractChecker;
use App\Services\Contracts\SchemaInferrer;
use Illuminate\Http\Request;

/**
 * Capture and check response contracts. A contract is a schema inferred from a
 * known-good response — no schema authoring — attached to a saved request.
 */
class ContractController extends Controller
{
    public function __construct(
        private readonly SchemaInferrer $inferrer,
        private readonly ContractChecker $checker,
    ) {
    }

    /**
     * Preview the schema that would be inferred from a response body, without
     * saving it. Powers the tester's "what will the contract look like" view.
     */
    public function infer(Request $request)
    {
        $validated = $request->validate(['response' => 'required|string|max:200000']);

        $schema = $this->inferrer->fromBody($validated['response']);

        if ($schema === null) {
            return response()->json(['message' => 'That response is not JSON — a contract needs structured data.'], 422);
        }

        return response()->json(['contract' => $schema]);
    }

    /**
     * Attach a contract to a saved request by inferring it from a response, or
     * clear it by sending an empty response.
     */
    public function update(Request $request, int $id)
    {
        $validated = $request->validate(['response' => 'nullable|string|max:200000']);

        $saved = SavedRequest::inWorkspaceOf($request->user())->findOrFail($id);

        if (($validated['response'] ?? '') === '') {
            $saved->update(['contract' => null]);

            return response()->json(['contract' => null]);
        }

        $schema = $this->inferrer->fromBody($validated['response']);

        if ($schema === null) {
            return response()->json(['message' => 'That response is not JSON — a contract needs structured data.'], 422);
        }

        $saved->update(['contract' => $schema]);

        return response()->json(['contract' => $schema]);
    }

    /**
     * Check a response against a stored contract, without saving. Lets the
     * tester show live conformance as you send requests.
     */
    public function check(Request $request, int $id)
    {
        $validated = $request->validate(['response' => 'nullable|string']);

        $saved = SavedRequest::inWorkspaceOf($request->user())->findOrFail($id);

        if (empty($saved->contract)) {
            return response()->json(['message' => 'This request has no contract yet.'], 422);
        }

        return response()->json(
            $this->checker->fromBody($saved->contract, $validated['response'] ?? null)
        );
    }
}
