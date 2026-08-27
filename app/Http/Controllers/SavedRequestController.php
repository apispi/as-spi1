<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SavedRequest;
use App\Rules\TemplatedUrl;
use App\Services\Assertions\Assertion;
use Illuminate\Validation\Rule;

class SavedRequestController extends Controller
{
    /**
     * Saved-request cap for the free plan, matching the pricing page.
     * Admins are exempt; paid plans will lift this when billing exists.
     *
     * Raised from 10 when collections shipped: a collection is built out of
     * saved requests, so one realistic smoke suite (login, list, create,
     * fetch, update, delete) used most of the old quota and a second was
     * impossible. The cap now sits above the collection cap (25) times a
     * couple of suites, so it bounds abuse without blocking normal use.
     */
    public const FREE_PLAN_LIMIT = 60;

    public function index(Request $request)
    {
        return response()->json(
            SavedRequest::inWorkspaceOf($request->user())->with('owner:id,name')->latest()->get()
        );
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if (! $user->isAdmin() && $user->savedRequests()->count() >= self::FREE_PLAN_LIMIT) {
            return response()->json([
                'message' => 'Free plan limit reached ('.self::FREE_PLAN_LIMIT.' saved requests). Delete one to save another.',
            ], 422);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'protocol' => 'nullable|string|in:rest,mcp,a2a,grpc,mqtt,amqp',
            'method' => 'required|string',
            // A saved request stores the template, not the resolved target, so
            // "https://{{host}}/users" must survive validation. The real URL is
            // validated (and SSRF-checked) when the request is sent.
            'url' => ['required', 'string', 'max:2048', new TemplatedUrl],
            'headers' => 'nullable|array',
            'body' => 'nullable|string',
            'params' => 'nullable|array',
            // Assertions may be attached at save time; the operator list is
            // closed so anything stored is guaranteed evaluable.
            'assertions' => 'nullable|array|max:'.AssertionController::MAX_ASSERTIONS,
            'assertions.*.path' => 'required|string|max:255',
            'assertions.*.operator' => ['required', 'string', Rule::in(Assertion::operators())],
            'assertions.*.expected' => 'nullable',
            'assertions.*.description' => 'nullable|string|max:255',
        ]);

        $validated['protocol'] = $validated['protocol'] ?? 'rest';

        $savedRequest = $user->savedRequests()->create($validated);

        return response()->json($savedRequest, 201);
    }

    public function destroy(Request $request, $id)
    {
        $savedRequest = SavedRequest::inWorkspaceOf($request->user())->findOrFail($id);
        $savedRequest->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
