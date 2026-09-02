<?php

namespace App\Http\Controllers;

use App\Models\Environment;
use App\Models\InspectionReport;
use App\Models\SavedRequest;
use App\Rules\PubliclyRoutableUrl;
use App\Services\Fuzz\FuzzRunner;
use App\Services\Variables\VariableResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Contract-driven fuzzing of a saved request: mutate its body into adversarial
 * variants and report which the endpoint mishandles.
 */
class FuzzController extends Controller
{
    public function fuzz(Request $request, FuzzRunner $runner, int $id)
    {
        $saved = SavedRequest::inWorkspaceOf($request->user())->findOrFail($id);

        if (($saved->protocol ?: 'rest') !== 'rest') {
            return response()->json(['message' => 'Fuzzing targets REST requests with a JSON body.'], 422);
        }
        if (trim((string) $saved->body) === '') {
            return response()->json(['message' => 'This request has no body to fuzz.'], 422);
        }

        // Resolve {{variables}} against an optional environment, exactly as a
        // collection run would.
        $selector = $request->input('environment_id') ?? $request->input('environment');
        $variables = $this->environmentMap($request->user(), $selector);

        $resolver = new VariableResolver;
        $resolved = $resolver->resolve([
            'url' => $saved->url,
            'headers' => $saved->headers ?? [],
            'body' => $saved->body,
        ], $variables);

        // The resolved URL is what we actually hit, so SSRF-check it here.
        if (Validator::make(['url' => $resolved['url']], ['url' => ['required', 'url', new PubliclyRoutableUrl]])->fails()) {
            return response()->json(['message' => 'The resolved URL is not a valid public URL.'], 422);
        }

        $result = $runner->run([
            'method' => $saved->method,
            'url' => $resolved['url'],
            'headers' => $resolved['headers'],
            'body' => $resolved['body'],
        ]);

        $report = InspectionReport::create([
            'user_id' => $request->user()->id,
            'type' => 'fuzz',
            'summary' => sprintf(
                '%s — %s (%d variants, %d finding%s)',
                $saved->name,
                $result['passed'] ? 'handled cleanly' : 'issues found',
                $result['total'],
                $result['findings'],
                $result['findings'] === 1 ? '' : 's'
            ),
            'data' => $result,
        ]);

        return response()->json($result + ['report_id' => $report->id], $result['passed'] ? 200 : 422);
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
