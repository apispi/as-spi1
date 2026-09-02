<?php

namespace App\Http\Controllers;

use App\Models\Environment;
use App\Models\InspectionReport;
use App\Models\SavedRequest;
use App\Rules\PubliclyRoutableUrl;
use App\Services\Perf\LoadTester;
use App\Services\Variables\VariableResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Performance profiling of a saved request: a bounded batch of samples, with a
 * latency distribution and error rate.
 */
class PerfController extends Controller
{
    public function run(Request $request, LoadTester $tester, int $id)
    {
        $saved = SavedRequest::inWorkspaceOf($request->user())->findOrFail($id);

        if (($saved->protocol ?: 'rest') !== 'rest') {
            return response()->json(['message' => 'Performance profiling targets REST requests.'], 422);
        }

        $validated = $request->validate([
            'samples' => 'nullable|integer|min:1|max:'.LoadTester::MAX_SAMPLES,
            'environment_id' => 'nullable',
        ]);

        // Resolve {{variables}} against an environment, like a collection run.
        $variables = $this->environmentMap($request->user(), $request->input('environment_id'));
        $resolver = new VariableResolver;
        $resolved = $resolver->resolve([
            'url' => $saved->url,
            'headers' => $saved->headers ?? [],
            'body' => $saved->body,
        ], $variables);

        if (Validator::make(['url' => $resolved['url']], ['url' => ['required', 'url', new PubliclyRoutableUrl]])->fails()) {
            return response()->json(['message' => 'The resolved URL is not a valid public URL.'], 422);
        }

        $result = $tester->run([
            'protocol' => 'rest',
            'method' => $saved->method,
            'url' => $resolved['url'],
            'headers' => $resolved['headers'],
            'body' => $resolved['body'],
        ], $validated['samples'] ?? 20);

        $report = InspectionReport::create([
            'user_id' => $request->user()->id,
            'type' => 'perf',
            'summary' => sprintf(
                '%s — %d samples, %s%% ok, p95 %s ms',
                $saved->name,
                $result['samples'],
                $result['success_rate'],
                $result['latency']['p95'] ?? '—'
            ),
            'data' => $result,
        ]);

        return response()->json($result + ['report_id' => $report->id]);
    }

    private function environmentMap($user, mixed $selector): array
    {
        $env = null;
        if ($selector !== null && $selector !== '') {
            $env = is_numeric($selector)
                ? Environment::inWorkspaceOf($user)->find((int) $selector)
                : Environment::inWorkspaceOf($user)->where('name', $selector)->first();
        } else {
            $env = Environment::inWorkspaceOf($user)->where('is_default', true)->first();
        }

        return $env?->map() ?? [];
    }
}
