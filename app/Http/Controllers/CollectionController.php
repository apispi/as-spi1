<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\Environment;
use App\Models\InspectionReport;
use App\Services\Collections\CollectionRunner;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CollectionController extends Controller
{
    public function index(Request $request)
    {
        $collections = Collection::inWorkspaceOf($request->user())
            ->with(['owner:id,name', 'steps.savedRequest:id,name,protocol,method,url'])
            ->orderBy('name')
            ->get();

        return response()->json($collections);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if ($user->collections()->count() >= Collection::MAX_PER_USER) {
            return response()->json([
                'message' => 'Collection limit reached ('.Collection::MAX_PER_USER.').',
            ], 422);
        }

        $validated = $this->validated($request, null);

        $collection = $user->collections()->create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'continue_on_failure' => $validated['continue_on_failure'] ?? false,
        ]);

        $this->syncSteps($collection, $validated['steps'] ?? [], $request);

        return response()->json($this->fresh($collection), 201);
    }

    public function update(Request $request, int $id)
    {
        $collection = Collection::inWorkspaceOf($request->user())->findOrFail($id);

        $validated = $this->validated($request, $collection);

        $collection->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'continue_on_failure' => $validated['continue_on_failure'] ?? false,
        ]);

        if (array_key_exists('steps', $validated)) {
            $this->syncSteps($collection, $validated['steps'], $request);
        }

        return response()->json($this->fresh($collection));
    }

    public function destroy(Request $request, int $id)
    {
        Collection::inWorkspaceOf($request->user())->findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted']);
    }

    /**
     * Run the collection and persist the result as a shareable report.
     */
    public function run(Request $request, CollectionRunner $runner, int $id)
    {
        $user = $request->user();
        $collection = Collection::inWorkspaceOf($user)->findOrFail($id);

        if ($collection->steps()->count() === 0) {
            return response()->json(['message' => 'This collection has no steps.'], 422);
        }

        $environment = null;
        $selector = $request->input('environment_id') ?? $request->input('environment');

        if ($selector !== null && $selector !== '') {
            $environment = is_numeric($selector)
                ? \App\Models\Environment::inWorkspaceOf($user)->find((int) $selector)
                : \App\Models\Environment::inWorkspaceOf($user)->where('name', $selector)->first();

            if (! $environment) {
                return response()->json(['message' => 'Unknown environment: '.$selector], 422);
            }
        } else {
            $environment = \App\Models\Environment::inWorkspaceOf($user)->where('is_default', true)->first();
        }

        $result = $runner->run($collection, $environment);

        $report = InspectionReport::create([
            'user_id' => $user->id,
            'type' => 'collection_run',
            'summary' => sprintf(
                '%s — %d/%d passed%s',
                $collection->name,
                $result['passed_count'],
                $result['total'],
                $environment ? ' ('.$environment->name.')' : ''
            ),
            'data' => $result,
        ]);

        return response()->json($result + ['report_id' => $report->id], $result['passed'] ? 200 : 422);
    }

    /**
     * Run the collection against two environments and diff the responses —
     * "does staging behave like production?". Persisted as a parity report.
     */
    public function parity(Request $request, CollectionRunner $runner, \App\Services\Collections\ParityChecker $checker, int $id)
    {
        $user = $request->user();
        $collection = Collection::inWorkspaceOf($user)->findOrFail($id);

        if ($collection->steps()->count() === 0) {
            return response()->json(['message' => 'This collection has no steps.'], 422);
        }

        $validated = $request->validate([
            'environment_a' => 'required',
            'environment_b' => 'required|different:environment_a',
        ]);

        $envA = $this->resolveEnvironment($user, $validated['environment_a']);
        $envB = $this->resolveEnvironment($user, $validated['environment_b']);

        if (! $envA || ! $envB) {
            return response()->json(['message' => 'One of the environments was not found.'], 422);
        }

        // Bodies are captured only to diff them here; the parity report keeps
        // the structural diff, not the raw (possibly large or secret) bodies.
        $runA = $runner->run($collection, $envA, captureBodies: true);
        $runB = $runner->run($collection, $envB, captureBodies: true);

        $parity = $checker->compare($runA, $runB);

        $report = InspectionReport::create([
            'user_id' => $user->id,
            'type' => 'parity',
            'summary' => sprintf(
                '%s — %s vs %s: %s',
                $collection->name,
                $envA->name,
                $envB->name,
                $parity['in_parity'] ? 'in parity' : $parity['diverged_count'].' step(s) diverged'
            ),
            'data' => $parity,
        ]);

        return response()->json($parity + ['report_id' => $report->id], $parity['in_parity'] ? 200 : 422);
    }

    private function resolveEnvironment($user, mixed $selector)
    {
        return is_numeric($selector)
            ? \App\Models\Environment::inWorkspaceOf($user)->find((int) $selector)
            : \App\Models\Environment::inWorkspaceOf($user)->where('name', (string) $selector)->first();
    }

    private function fresh(Collection $collection)
    {
        return $collection->fresh()->load('steps.savedRequest:id,name,protocol,method,url');
    }

    /**
     * Replace the collection's steps, keeping the submitted order.
     */
    private function syncSteps(Collection $collection, array $steps, Request $request): void
    {
        // Only the caller's own saved requests may be referenced.
        // Steps may reference any saved request in the shared workspace.
        $ownedIds = \App\Models\SavedRequest::inWorkspaceOf($request->user())->pluck('id')->all();

        $collection->steps()->delete();

        foreach (array_values($steps) as $position => $step) {
            if (! in_array((int) $step['saved_request_id'], $ownedIds, true)) {
                continue;
            }

            $collection->steps()->create([
                'saved_request_id' => (int) $step['saved_request_id'],
                'position' => $position,
                'extract' => array_values(array_filter(
                    $step['extract'] ?? [],
                    fn ($rule) => ! empty($rule['name']) && ! empty($rule['path'])
                )),
            ]);
        }
    }

    private function validated(Request $request, ?Collection $existing): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:80',
                Rule::unique('collections', 'name')
                    ->whereIn('user_id', $request->user()->workspaceUserIds())
                    ->ignore($existing?->id),
            ],
            'description' => 'nullable|string|max:500',
            'continue_on_failure' => 'nullable|boolean',
            'steps' => 'nullable|array|max:'.Collection::MAX_STEPS,
            'steps.*.saved_request_id' => 'required|integer',
            'steps.*.extract' => 'nullable|array|max:10',
            'steps.*.extract.*.name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'steps.*.extract.*.path' => 'required|string|max:255',
        ]);
    }
}
