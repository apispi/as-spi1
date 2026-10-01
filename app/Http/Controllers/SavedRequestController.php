<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SavedRequest;
use App\Models\WorkspaceActivity;
use App\Rules\TemplatedUrl;
use App\Services\Assertions\Assertion;
use App\Services\Auth\RequestAuthenticator;
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
        $requests = SavedRequest::inWorkspaceOf($request->user())
            ->with(['owner:id,name', 'steps.collection:id,name'])
            ->latest()
            ->get();

        return response()->json($requests->map(fn ($saved) => $this->present($saved))->values());
    }

    /**
     * The shape every saved-request response uses.
     *
     * Kept in one place because the list renders the usage and flag fields: an
     * endpoint that returned the bare model would hand the client a row with
     * those missing, and the row would render as unused.
     */
    private function present(SavedRequest $saved): array
    {
        $saved->loadMissing(['owner:id,name', 'steps.collection:id,name']);

        return $saved->toArray() + [
            // Deleting a request removes it from these, so a caller can say so
            // before doing it rather than after.
            'used_by' => $saved->steps->map(fn ($step) => $step->collection?->name)
                ->filter()->unique()->values()->all(),
            'has_assertions' => ! empty($saved->assertions),
            'has_contract' => ! empty($saved->contract),
            'has_auth' => is_array($saved->auth) && ($saved->auth['scheme'] ?? 'inherit') !== 'inherit',
        ];
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if (! $user->isAdmin() && $user->savedRequests()->count() >= self::FREE_PLAN_LIMIT) {
            return response()->json([
                'message' => 'Free plan limit reached ('.self::FREE_PLAN_LIMIT.' saved requests). Delete one to save another.',
            ], 422);
        }

        $validated = $this->validated($request);

        $validated['protocol'] = $validated['protocol'] ?? 'rest';

        $savedRequest = $user->savedRequests()->create($validated);

        return response()->json($this->present($savedRequest), 201);
    }

    /** The shape of a saved request, shared by create and edit. */
    private function validated(Request $request): array
    {
        return $request->validate([
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
            // The auth helper's config. Stored as a template: the values are
            // normally {{variables}}, so the credential stays in a secret
            // environment variable rather than in this row.
        ] + RequestAuthenticator::rules());
    }

    /**
     * Edit a saved request in place.
     *
     * Without this the only way to change a URL was to delete and re-save,
     * which takes the request's id with it — and every collection step
     * pointing at it. Updating keeps the id, so the collections built on it
     * keep working.
     *
     * The edit flows through RecordsActivity like any other change, so it
     * appears in the workspace activity feed and can be undone there.
     */
    public function update(Request $request, int $id)
    {
        $savedRequest = SavedRequest::inWorkspaceOf($request->user())->findOrFail($id);

        // validate() returns only the fields that were actually sent, so a
        // payload that omits assertions (they have their own endpoint, as do
        // the contract and the snapshot) leaves them alone rather than
        // clearing them.
        $savedRequest->update($this->validated($request));

        return response()->json($this->present($savedRequest->fresh()));
    }

    /**
     * Delete a saved request.
     *
     * The step foreign key cascades, so any collection using this request
     * loses that step. That is intended, but it used to happen silently: the
     * affected collections are now named back to the caller, and each one gets
     * an activity entry so a colleague can see why their suite got shorter.
     */
    public function destroy(Request $request, $id)
    {
        $savedRequest = SavedRequest::inWorkspaceOf($request->user())
            ->with('steps.collection:id,name')
            ->findOrFail($id);

        $affected = $savedRequest->steps->map(fn ($step) => $step->collection)
            ->filter()->unique('id')->values();
        $removedSteps = $savedRequest->steps->count();
        $name = $savedRequest->name;

        $savedRequest->delete();

        foreach ($affected as $collection) {
            WorkspaceActivity::record(
                $request->user()->id,
                'collection',
                $collection->id,
                $collection->name,
                WorkspaceActivity::ACTION_UPDATED,
                'lost a step — the request "'.$name.'" was deleted'
            );
        }

        return response()->json([
            'message' => 'Deleted',
            'removed_steps' => $removedSteps,
            'used_by' => $affected->pluck('name')->all(),
        ]);
    }

    /**
     * Clone a saved request into a new one owned by the caller, with a
     * workspace-unique name. Assertions and contract carry over; the captured
     * snapshot does not — a copy earns its own baseline.
     */
    public function duplicate(Request $request, int $id)
    {
        $user = $request->user();
        $source = SavedRequest::inWorkspaceOf($user)->findOrFail($id);

        if (! $user->isAdmin() && $user->savedRequests()->count() >= self::FREE_PLAN_LIMIT) {
            return response()->json([
                'message' => 'Free plan limit reached ('.self::FREE_PLAN_LIMIT.' saved requests). Delete one to save another.',
            ], 422);
        }

        $copy = $user->savedRequests()->create([
            'name' => $this->uniqueCopyName($user, $source->name),
            'protocol' => $source->protocol,
            'method' => $source->method,
            'url' => $source->url,
            'headers' => $source->headers,
            'auth' => $source->auth,
            'body' => $source->body,
            'params' => $source->params,
            'assertions' => $source->assertions,
            'contract' => $source->contract,
        ]);

        return response()->json($this->present($copy->fresh()), 201);
    }

    private function uniqueCopyName($user, string $base): string
    {
        $name = trim($base).' (copy)';
        $i = 2;
        while (SavedRequest::inWorkspaceOf($user)->where('name', $name)->exists()) {
            $name = trim($base).' (copy '.$i++.')';
        }

        return mb_substr($name, 0, 255);
    }
}
